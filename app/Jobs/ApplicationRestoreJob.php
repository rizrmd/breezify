<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\LocalFileVolume;
use App\Models\S3Storage;
use App\Models\ScheduledApplicationBackup;
use App\Models\ScheduledApplicationBackupExecution;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Visus\Cuid2\Cuid2;

class ApplicationRestoreJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $maxExceptions = 1;

    public Server $server;

    public Application $application;

    public ScheduledApplicationBackupExecution $backupExecution;

    public ?S3Storage $s3 = null;

    public string $restore_location;

    public array $backupConfig = [];

    public $timeout = 3600;

    private ?string $restore_log_uuid = null;

    public function __construct(
        public string $application_uuid,
        public string $backup_execution_uuid,
        public ?string $restore_uuid = null
    ) {
        $this->onQueue('high');
        $this->restore_log_uuid = $restore_uuid ?? new Cuid2;
    }

    public function handle(): void
    {
        try {
            // Get application
            $this->application = Application::where('uuid', $this->application_uuid)->firstOrFail();

            // Get backup execution
            $this->backupExecution = ScheduledApplicationBackupExecution::where('uuid', $this->backup_execution_uuid)->firstOrFail();

            // Get server from application
            $destination = $this->application->destination;
            if (! $destination) {
                throw new \Exception('Application destination not found');
            }
            $this->server = $destination->server;
            if (! $this->server) {
                throw new \Exception('Server not found for application');
            }

            // Get S3 storage from backup
            $scheduledBackup = $this->backupExecution->scheduledApplicationBackup;
            if (! $scheduledBackup) {
                throw new \Exception('Scheduled backup not found');
            }
            $this->s3 = $scheduledBackup->s3;

            // Setup restore location
            $this->restore_location = '/tmp/restore-'.$this->restore_log_uuid.'.tar.gz';

            // Download backup from S3
            $this->downloadFromS3();

            // Extract and validate backup
            $this->extractAndValidate();

            // Check for conflicts
            $conflicts = $this->checkConflicts();
            if (! empty($conflicts)) {
                Log::warning('Application restore has configuration conflicts', [
                    'application' => $this->application->name,
                    'conflicts' => $conflicts,
                ]);
            }

            // Start database transaction for atomic restore
            DB::beginTransaction();

            try {
                // Restore configuration
                $this->restoreConfiguration();

                // Restore file volumes
                $this->restoreFileVolumes();

                // Commit transaction
                DB::commit();

                Log::info('Application restored successfully', [
                    'application' => $this->application->name,
                    'backup_execution_uuid' => $this->backup_execution_uuid,
                ]);
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            // Cleanup restore files
            $this->cleanupRestoreFiles();
        } catch (\Throwable $e) {
            Log::error('Application restore failed', [
                'application' => $this->application->name ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Cleanup on failure
            $this->cleanupRestoreFiles();

            throw $e;
        }
    }

    private function downloadFromS3(): void
    {
        try {
            if (! $this->s3) {
                throw new \Exception('S3 storage not configured');
            }

            $filename = $this->backupExecution->filename;
            if (! $filename) {
                throw new \Exception('Backup filename not found');
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
            $commands[] = "docker run -d --name restore-of-{$this->restore_log_uuid} --rm -v {$this->restore_location}:{$this->restore_location} {$fullImageName}";

            // Escape S3 credentials
            $escapedEndpoint = escapeshellarg($endpoint);
            $escapedKey = escapeshellarg($key);
            $escapedSecret = escapeshellarg($secret);
            $s3Path = escapeshellarg($bucket.$filename);

            $commands[] = "docker exec restore-of-{$this->restore_log_uuid} mc alias set temporary {$escapedEndpoint} {$escapedKey} {$escapedSecret}";
            $commands[] = "docker exec restore-of-{$this->restore_log_uuid} mc cp temporary/{$s3Path} {$this->restore_location}";

            instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);

            Log::info('Backup downloaded from S3', [
                'application' => $this->application->name,
                'filename' => $filename,
            ]);
        } catch (\Throwable $e) {
            throw new \Exception('Failed to download backup from S3: '.$e->getMessage());
        } finally {
            // Cleanup helper container
            $command = "docker rm -f restore-of-{$this->restore_log_uuid}";
            instant_remote_process([$command], $this->server, throwError: false);
        }
    }

    private function extractAndValidate(): void
    {
        try {
            $extractDir = '/tmp/restore-'.$this->restore_log_uuid;
            $commands = collect([]);

            // Create extract directory
            $commands->push("mkdir -p {$extractDir}");

            // Extract tarball
            $escapedPath = escapeshellarg($this->restore_location);
            $commands->push("tar -xzf {$escapedPath} -C {$extractDir}");

            instant_remote_process($commands, $this->server);

            // Read config JSON
            $configFile = $extractDir.'/backup-config-*.json';
            $configJson = instant_remote_process(["cat {$configFile}"], $this->server);
            $this->backupConfig = json_decode($configJson, true);

            if (! $this->backupConfig) {
                throw new \Exception('Invalid backup configuration');
            }

            // Verify checksum
            $storedChecksum = $this->backupExecution->checksum;
            if ($storedChecksum) {
                $currentChecksum = instant_remote_process(["sha256sum {$escapedPath} | cut -d' ' -f1"], $this->server);
                if (trim($currentChecksum) !== $storedChecksum) {
                    throw new \Exception('Checksum verification failed. Backup may be corrupted.');
                }
            }

            Log::info('Backup extracted and validated', [
                'application' => $this->application->name,
                'config_backup_date' => data_get($this->backupConfig, 'backup_metadata.backup_created_at'),
            ]);
        } catch (\Throwable $e) {
            throw new \Exception('Failed to extract and validate backup: '.$e->getMessage());
        }
    }

    private function checkConflicts(): array
    {
        $conflicts = [];

        try {
            // Check if application has changed since backup
            $backupAppId = data_get($this->backupConfig, 'id');
            $backupAppUuid = data_get($this->backupConfig, 'uuid');

            if ($backupAppId !== $this->application->id || $backupAppUuid !== $this->application->uuid) {
                $conflicts[] = 'Application ID or UUID mismatch';
            }

            // Check if configuration hash has changed
            // Note: This is a simplified check. In production, you might want to compare specific fields
            $backupName = data_get($this->backupConfig, 'name');
            if ($backupName !== $this->application->name) {
                $conflicts[] = 'Application name has changed';
            }

            return $conflicts;
        } catch (\Throwable $e) {
            Log::warning('Error checking configuration conflicts', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function restoreConfiguration(): void
    {
        try {
            // Restore basic application settings from backup
            $settings = data_get($this->backupConfig, 'settings');
            if ($settings) {
                $this->application->settings = $settings;
            }

            // Restore build configuration
            $build = data_get($this->backupConfig, 'build');
            if ($build) {
                $this->application->build_pack = data_get($build, 'type');
                $this->application->static_image = data_get($build, 'static_image');
                $this->application->base_directory = data_get($build, 'base_directory');
                $this->application->publish_directory = data_get($build, 'publish_directory');
                $this->application->dockerfile = data_get($build, 'dockerfile');
                $this->application->dockerfile_location = data_get($build, 'dockerfile_location');
                $this->application->dockerfile_target_build = data_get($build, 'dockerfile_target_build');
                $this->application->docker_compose = data_get($build, 'docker_compose');
                $this->application->docker_compose_location = data_get($build, 'docker_compose_location');
                $this->application->docker_compose_raw = data_get($build, 'docker_compose_raw');
                $this->application->docker_compose_domains = data_get($build, 'docker_compose_domains');
                $this->application->install_command = data_get($build, 'install_command');
                $this->application->build_command = data_get($build, 'build_command');
                $this->application->start_command = data_get($build, 'start_command');
            }

            // Restore source configuration
            $source = data_get($this->backupConfig, 'source');
            if ($source) {
                $this->application->git_repository = data_get($source, 'git_repository');
                $this->application->git_branch = data_get($source, 'git_branch');
                $this->application->git_commit_sha = data_get($source, 'git_commit_sha');
            }

            // Restore domains
            $domains = data_get($this->backupConfig, 'domains');
            if ($domains) {
                $this->application->fqdn = data_get($domains, 'fqdn');
                $this->application->ports_exposes = data_get($domains, 'ports_exposes');
                $this->application->ports_mappings = data_get($domains, 'ports_mappings');
            }

            // Restore limits
            $limits = data_get($this->backupConfig, 'limits');
            if ($limits) {
                $this->application->limits_memory = data_get($limits, 'memory');
                $this->application->limits_memory_swap = data_get($limits, 'memory_swap');
                $this->application->limits_memory_swappiness = data_get($limits, 'memory_swappiness');
                $this->application->limits_memory_reservation = data_get($limits, 'memory_reservation');
                $this->application->limits_cpus = data_get($limits, 'cppus');
                $this->application->limits_cpuset = data_get($limits, 'cpuset');
                $this->application->limits_cpu_shares = data_get($limits, 'cpu_shares');
            }

            // Restore health checks
            $healthCheck = data_get($this->backupConfig, 'health_check');
            if ($healthCheck) {
                $this->application->health_check_enabled = data_get($healthCheck, 'health_check_enabled');
                $this->application->health_check_path = data_get($healthCheck, 'health_check_path');
                $this->application->health_check_port = data_get($healthCheck, 'health_check_port');
                $this->application->health_check_host = data_get($healthCheck, 'health_check_host');
                $this->application->health_check_method = data_get($healthCheck, 'health_check_method');
                $this->application->health_check_return_code = data_get($healthCheck, 'health_check_return_code');
                $this->application->health_check_scheme = data_get($healthCheck, 'health_check_scheme');
                $this->application->health_check_response_text = data_get($healthCheck, 'health_check_response_text');
                $this->application->health_check_interval = data_get($healthCheck, 'health_check_interval');
                $this->application->health_check_timeout = data_get($healthCheck, 'health_check_timeout');
                $this->application->health_check_retries = data_get($healthCheck, 'health_check_retries');
                $this->application->health_check_start_period = data_get($healthCheck, 'health_check_start_period');
            }

            $this->application->save();

            // Restore environment variables
            $this->restoreEnvironmentVariables();

            Log::info('Application configuration restored', [
                'application' => $this->application->name,
            ]);
        } catch (\Throwable $e) {
            throw new \Exception('Failed to restore configuration: '.$e->getMessage());
        }
    }

    private function restoreEnvironmentVariables(): void
    {
        try {
            // Delete existing environment variables
            $this->application->environment_variables()->delete();

            // Restore production environment variables
            $productionEnvs = data_get($this->backupConfig, 'environment_variables.production', []);
            foreach ($productionEnvs as $env) {
                EnvironmentVariable::create([
                    'key' => data_get($env, 'key'),
                    'value' => data_get($env, 'value'),
                    'is_preview' => false,
                    'is_multiline' => data_get($env, 'is_multiline', false),
                    'resourceable_type' => $this->application->getMorphClass(),
                    'resourceable_id' => $this->application->id,
                ]);
            }

            // Restore preview environment variables
            $previewEnvs = data_get($this->backupConfig, 'environment_variables.preview', []);
            foreach ($previewEnvs as $env) {
                EnvironmentVariable::create([
                    'key' => data_get($env, 'key'),
                    'value' => data_get($env, 'value'),
                    'is_preview' => true,
                    'is_multiline' => data_get($env, 'is_multiline', false),
                    'resourceable_type' => $this->application->getMorphClass(),
                    'resourceable_id' => $this->application->id,
                ]);
            }

            Log::info('Environment variables restored', [
                'application' => $this->application->name,
                'count' => count($productionEnvs) + count($previewEnvs),
            ]);
        } catch (\Throwable $e) {
            throw new \Exception('Failed to restore environment variables: '.$e->getMessage());
        }
    }

    private function restoreFileVolumes(): void
    {
        try {
            $extractDir = '/tmp/restore-'.$this->restore_log_uuid;
            $fileVolumes = data_get($this->backupConfig, 'file_volumes', []);

            if (empty($fileVolumes)) {
                Log::info('No file volumes to restore');
                return;
            }

            // Check if volumes tarball exists
            $volumesTarball = instant_remote_process(["test -f {$extractDir}/volumes*.tar.gz && echo OK || echo NOK"], $this->server);

            if ($volumesTarball !== 'OK') {
                Log::info('No file volumes tarball found in backup');
                return;
            }

            // Extract volumes
            $commands = collect([]);
            $commands->push("cd {$extractDir} && tar -xzf volumes*.tar.gz");

            foreach ($fileVolumes as $volume) {
                $fsPath = data_get($volume, 'fs_path');
                $mountPath = data_get($volume, 'mount_path');
                $isDirectory = data_get($volume, 'is_directory', false);

                if (! $fsPath || ! $mountPath) {
                    continue;
                }

                // Handle relative paths
                $workdir = $this->application->workdir();
                if (str($fsPath)->startsWith('.')) {
                    $fsPath = str($fsPath)->after('.');
                    $fsPath = $workdir.$fsPath;
                }

                // Validate and escape path
                validateShellSafePath($fsPath, 'file volume restore path');
                $escapedFsPath = escapeshellarg($fsPath);

                // Create parent directory if needed
                $parentDir = str($fsPath)->beforeLast('/');
                if ($parentDir && $parentDir !== '' && $parentDir !== '.') {
                    $commands->push("mkdir -p ".escapeshellarg($parentDir));
                }

                // Restore file or directory
                if ($isDirectory) {
                    $commands->push("mkdir -p {$escapedFsPath}");
                } else {
                    // Copy file from extract directory
                    $basename = basename($fsPath);
                    $sourceFile = "{$extractDir}/{$basename}";
                    $commands->push("test -f {$sourceFile} && cp {$sourceFile} {$escapedFsPath} || true");
                }

                // Recreate LocalFileVolume record if it doesn't exist
                $existingVolume = $this->application->fileStorages()
                    ->where('fs_path', $fsPath)
                    ->first();

                if (! $existingVolume) {
                    LocalFileVolume::create([
                        'resourceable_type' => $this->application->getMorphClass(),
                        'resourceable_id' => $this->application->id,
                        'fs_path' => $fsPath,
                        'mount_path' => $mountPath,
                        'is_directory' => $isDirectory,
                    ]);
                }
            }

            instant_remote_process($commands, $this->server);

            Log::info('File volumes restored', [
                'application' => $this->application->name,
                'count' => count($fileVolumes),
            ]);
        } catch (\Throwable $e) {
            throw new \Exception('Failed to restore file volumes: '.$e->getMessage());
        }
    }

    private function cleanupRestoreFiles(): void
    {
        try {
            $extractDir = '/tmp/restore-'.$this->restore_log_uuid;
            $escapedPath = escapeshellarg($this->restore_location);
            $escapedDir = escapeshellarg($extractDir);

            instant_remote_process([
                "rm -f {$escapedPath}",
                "rm -rf {$escapedDir}",
            ], $this->server, throwError: false);
        } catch (\Throwable $e) {
            Log::warning('Failed to cleanup restore files', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getFullImageName(): string
    {
        $helperImage = config('constants.coolify.helper_image');
        $latestVersion = getHelperVersion();

        return "{$helperImage}:{$latestVersion}";
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('ApplicationRestore permanently failed', [
            'job' => 'ApplicationRestoreJob',
            'application' => $this->application->name ?? 'unknown',
            'backup_execution_uuid' => $this->backup_execution_uuid,
            'total_attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        $this->cleanupRestoreFiles();
    }
}
