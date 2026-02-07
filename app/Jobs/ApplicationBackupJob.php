<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\S3Storage;
use App\Models\ScheduledApplicationBackup;
use App\Models\ScheduledApplicationBackupExecution;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Application\BackupFailed;
use App\Notifications\Application\BackupSuccess;
use App\Notifications\Application\BackupSuccessWithS3Warning;
use App\Services\ConfigurationGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Visus\Cuid2\Cuid2;

class ApplicationBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $maxExceptions = 1;

    public ?Team $team = null;

    public Server $server;

    public Application $application;

    public ?ScheduledApplicationBackupExecution $backup_log = null;

    public string $backup_status = 'failed';

    public ?string $backup_location = null;

    public string $backup_dir;

    public string $backup_file;

    public int $size = 0;

    public ?string $backup_output = null;

    public ?string $error_output = null;

    public bool $s3_uploaded = false;

    public ?S3Storage $s3 = null;

    public $timeout = 3600;

    public ?string $backup_log_uuid = null;

    public function __construct(public ScheduledApplicationBackup $backup)
    {
        $this->onQueue('high');
        $this->timeout = $backup->timeout ?? 3600;
    }

    public function handle(): void
    {
        try {
            // Get team
            $this->team = Team::find($this->backup->team_id);
            if (! $this->team) {
                $this->backup->delete();

                return;
            }

            // Get application
            $this->application = $this->backup->application;
            if (! $this->application) {
                $this->backup->delete();

                return;
            }

            // Get server from application destination
            $destination = $this->application->destination;
            if (! $destination) {
                throw new \Exception('Application destination not found');
            }
            $this->server = $destination->server;
            if (! $this->server) {
                throw new \Exception('Server not found for application');
            }

            // Get S3 storage
            if ($this->backup->save_s3) {
                $this->s3 = $this->backup->s3;
            }

            // Create backup execution log
            $this->backup_log_uuid = new Cuid2;
            $this->backup_log = ScheduledApplicationBackupExecution::create([
                'uuid' => $this->backup_log_uuid,
                'status' => 'running',
                'scheduled_application_backup_id' => $this->backup->id,
            ]);

            // Setup backup directories
            $this->backup_dir = '/applications/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$this->application->uuid;
            $timestamp = now()->format('Y-m-d-H-i-s');
            $this->backup_file = 'backup-'.$timestamp.'.tar.gz';
            $this->backup_location = '/tmp/backup-'.$this->backup_log_uuid.'.tar.gz';

            // Collect application configuration using ConfigurationGenerator
            $configJson = $this->collectApplicationConfiguration();

            // Archive file volumes
            $volumesTarball = $this->archiveFileVolumes();

            // Create backup tarball combining config and volumes
            $this->createBackupTarball($configJson, $volumesTarball);

            // Calculate checksum
            $checksum = $this->calculateChecksum();

            // Calculate backup size
            $this->size = $this->calculateBackupSize();

            // Upload to S3 if enabled
            if ($this->backup->save_s3 && $this->s3) {
                try {
                    $this->uploadToS3();
                } catch (\Throwable $e) {
                    $this->add_to_error_output('S3 upload failed: '.$e->getMessage());
                    Log::error('S3 upload failed for application backup', [
                        'backup_id' => $this->backup->uuid,
                        'application' => $this->application->name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Update backup log with success
            $this->backup_status = 'success';
            $this->backup_log->update([
                'status' => 'success',
                'message' => $this->backup_output,
                'size' => $this->size,
                'filename' => $this->backup_dir.'/'.$this->backup_file,
                'checksum' => $checksum,
                'metadata' => [
                    'application_name' => $this->application->name,
                    'application_uuid' => $this->application->uuid,
                    'file_volumes_count' => $this->application->fileStorages()->count(),
                ],
                's3_uploaded' => $this->s3_uploaded,
                'finished_at' => now(),
            ]);

            // Apply retention policy
            if ($this->backup->save_s3 && $this->s3) {
                removeOldBackupsFromS3ForApplications($this->backup);
            }

            // Send notification
            $this->sendNotification();

            // Cleanup local backup file
            $this->cleanupLocalBackup();
        } catch (\Throwable $e) {
            $this->backup_status = 'failed';
            if ($this->backup_log) {
                $this->backup_log->update([
                    'status' => 'failed',
                    'message' => $this->error_output ?? $e->getMessage(),
                    'finished_at' => now(),
                ]);
            }

            // Send failure notification
            $this->team?->notify(new BackupFailed(
                application: $this->application,
                error_message: $e->getMessage(),
            ));

            throw $e;
        }
    }

    private function collectApplicationConfiguration(): string
    {
        try {
            // Use ConfigurationGenerator to export complete application configuration
            $configGenerator = new ConfigurationGenerator($this->application);
            $configArray = $configGenerator->toArray();

            // Add file volumes metadata (not included by ConfigurationGenerator)
            $configArray['file_volumes'] = $this->application->fileStorages()
                ->get(['uuid', 'fs_path', 'mount_path', 'is_directory'])
                ->toArray();

            // Add backup metadata
            $configArray['backup_metadata'] = [
                'application_uuid' => $this->application->uuid,
                'application_id' => $this->application->id,
                'backup_created_at' => now()->toIso8601String(),
                'file_volumes_count' => count($configArray['file_volumes']),
                'team_id' => $this->team->id,
            ];

            return json_encode($configArray, JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            throw new \Exception('Failed to collect application configuration: '.$e->getMessage());
        }
    }

    private function archiveFileVolumes(): ?string
    {
        $fileVolumes = $this->application->fileStorages;

        if ($fileVolumes->isEmpty()) {
            return null;
        }

        try {
            $volumesTempFile = '/tmp/volumes-'.$this->backup_log_uuid.'.tar.gz';
            $commands = collect([]);

            foreach ($fileVolumes as $volume) {
                $path = $volume->fs_path;
                $workdir = $this->application->workdir();

                // Handle relative paths
                if (str($path)->startsWith('.')) {
                    $path = str($path)->after('.');
                    $path = $workdir.$path;
                }

                // Validate and escape path
                validateShellSafePath($path, 'file volume path');
                $escapedPath = escapeshellarg($path);

                // Check if path exists
                $exists = instant_remote_process(["test -e {$escapedPath} && echo OK || echo NOK"], $this->server);

                if ($exists === 'OK') {
                    $commands->push("tar -rf /tmp/volumes-raw-{$this->backup_log_uuid}.tar -C $(dirname {$escapedPath}) $(basename {$escapedPath}) 2>&1 || true");
                }
            }

            if ($commands->isNotEmpty()) {
                // Create raw tar archive
                instant_remote_process([
                    "rm -f /tmp/volumes-raw-{$this->backup_log_uuid}.tar",
                ], $this->server);

                instant_remote_process($commands, $this->server);

                // Compress the tar archive
                instant_remote_process([
                    "if [ -f /tmp/volumes-raw-{$this->backup_log_uuid}.tar ]; then gzip -c /tmp/volumes-raw-{$this->backup_log_uuid}.tar > {$volumesTempFile}; rm -f /tmp/volumes-raw-{$this->backup_log_uuid}.tar; fi",
                ], $this->server);

                return $volumesTempFile;
            }

            return null;
        } catch (\Throwable $e) {
            $this->add_to_error_output('Failed to archive file volumes: '.$e->getMessage());
            throw $e;
        }
    }

    private function createBackupTarball(string $configJson, ?string $volumesTarball): void
    {
        try {
            $commands = collect([]);

            // Write config to file
            $escapedConfig = escapeshellarg($configJson);
            $commands->push("echo {$escapedConfig} > /tmp/backup-config-{$this->backup_log_uuid}.json");

            // Create tarball with config and volumes
            if ($volumesTarball) {
                $commands->push("tar -czf {$this->backup_location} -C /tmp backup-config-{$this->backup_log_uuid}.json -C /tmp {$volumesTarball}");
            } else {
                $commands->push("tar -czf {$this->backup_location} -C /tmp backup-config-{$this->backup_log_uuid}.json");
            }

            // Cleanup temp files
            $commands->push("rm -f /tmp/backup-config-{$this->backup_log_uuid}.json");
            if ($volumesTarball) {
                $commands->push("rm -f {$volumesTarball}");
            }

            instant_remote_process($commands, $this->server);

            $this->add_to_output('Backup tarball created successfully');
        } catch (\Throwable $e) {
            throw new \Exception('Failed to create backup tarball: '.$e->getMessage());
        }
    }

    private function calculateChecksum(): string
    {
        try {
            $escapedPath = escapeshellarg($this->backup_location);
            $checksum = instant_remote_process(["sha256sum {$escapedPath} | cut -d' ' -f1"], $this->server);

            return trim($checksum);
        } catch (\Throwable $e) {
            $this->add_to_error_output('Failed to calculate checksum: '.$e->getMessage());

            return '';
        }
    }

    private function calculateBackupSize(): int
    {
        try {
            $escapedPath = escapeshellarg($this->backup_location);
            $size = instant_remote_process(["stat -c%s {$escapedPath}"], $this->server);

            return (int) trim($size);
        } catch (\Throwable $e) {
            $this->add_to_error_output('Failed to calculate backup size: '.$e->getMessage());

            return 0;
        }
    }

    private function uploadToS3(): void
    {
        try {
            if (! $this->s3) {
                throw new \Exception('S3 storage not configured');
            }

            $network = $this->server->network;
            $bucket = $this->s3->bucket;
            $endpoint = $this->s3->endpoint;
            $key = $this->s3->key;
            $secret = $this->s3->secret;

            if (! $network) {
                throw new \Exception('Server network not found');
            }

            // Get helper image
            $fullImageName = $this->getFullImageName();

            // Start helper container
            $commands = [];
            $commands[] = "docker run -d --name backup-of-{$this->backup_log_uuid} --rm -v {$this->backup_location}:{$this->backup_location}:ro {$fullImageName}";

            // Escape S3 credentials
            $escapedEndpoint = escapeshellarg($endpoint);
            $escapedKey = escapeshellarg($key);
            $escapedSecret = escapeshellarg($secret);

            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc alias set temporary {$escapedEndpoint} {$escapedKey} {$escapedSecret}";
            $commands[] = "docker exec backup-of-{$this->backup_log_uuid} mc cp {$this->backup_location} temporary/{$bucket}{$this->backup_dir}/";

            instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);

            $this->s3_uploaded = true;
            $this->add_to_output('Backup uploaded to S3 successfully');
        } catch (\Throwable $e) {
            $this->s3_uploaded = false;
            $this->add_to_error_output($e->getMessage());
            throw $e;
        } finally {
            // Cleanup helper container
            $command = "docker rm -f backup-of-{$this->backup_log_uuid}";
            instant_remote_process([$command], $this->server, true, false, null, disableMultiplexing: true);
        }
    }

    private function getFullImageName(): string
    {
        $helperImage = config('constants.coolify.helper_image');
        $latestVersion = getHelperVersion();

        return "{$helperImage}:{$latestVersion}";
    }

    private function cleanupLocalBackup(): void
    {
        try {
            if ($this->backup_location) {
                $escapedPath = escapeshellarg($this->backup_location);
                instant_remote_process(["rm -f {$escapedPath}"], $this->server, throwError: false);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to cleanup local backup file', [
                'backup_id' => $this->backup->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendNotification(): void
    {
        if ($this->s3_uploaded) {
            $this->team->notify(new BackupSuccess(
                application: $this->application,
                size: $this->size,
            ));
        } elseif ($this->backup->save_s3) {
            $this->team->notify(new BackupSuccessWithS3Warning(
                application: $this->application,
                size: $this->size,
            ));
        }
    }

    private function add_to_output(string $message): void
    {
        if ($this->backup_output) {
            $this->backup_output .= "\n".$message;
        } else {
            $this->backup_output = $message;
        }
    }

    private function add_to_error_output(string $message): void
    {
        if ($this->error_output) {
            $this->error_output .= "\n".$message;
        } else {
            $this->error_output = $message;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('ApplicationBackup permanently failed', [
            'job' => 'ApplicationBackupJob',
            'backup_id' => $this->backup->uuid,
            'application' => $this->application?->name ?? 'unknown',
            'server' => $this->server?->name ?? 'unknown',
            'total_attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        if ($this->backup_log) {
            $this->backup_log->update([
                'status' => 'failed',
                'message' => 'Job permanently failed after '.$this->attempts().' attempts: '.($exception?->getMessage() ?? 'Unknown error'),
                'finished_at' => now(),
            ]);
        }

        $this->cleanupLocalBackup();
    }
}
