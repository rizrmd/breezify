<?php

namespace App\Livewire\Project\Application;

use App\Jobs\ApplicationBackupJob;
use App\Jobs\ApplicationRestoreJob;
use App\Models\ScheduledApplicationBackup;
use App\Models\ScheduledApplicationBackupExecution;
use App\Models\S3Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithPagination;

class BackupExecutionsList extends Component
{
    use AuthorizesRequests, WithPagination;

    public $application;

    public $scheduledBackup;

    public $filter = 'all';

    public $sort = 'date';

    public $confirmingRestore = false;

    public $selectedExecution = null;

    protected $queryString = [
        'filter' => ['except' => 'all'],
        'sort' => ['except' => 'date'],
    ];

    public function mount()
    {
        $this->scheduledBackup = $this->application->scheduledBackups()->first();
    }

    public function getListeners()
    {
        return [
            'refreshBackupExecutions' => '$refresh',
        ];
    }

    public function backupNow()
    {
        try {
            $this->authorize('manageBackups', $this->application);

            if (! $this->scheduledBackup || ! $this->scheduledBackup->s3_storage_id) {
                $this->dispatch('error', 'Please configure a backup schedule with S3 storage first.');
                return;
            }

            ApplicationBackupJob::dispatch($this->scheduledBackup);

            $this->dispatch('success', 'Backup job dispatched successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function confirmRestore($executionId)
    {
        try {
            $this->authorize('manageBackups', $this->application);

            $execution = ScheduledApplicationBackupExecution::findOrFail($executionId);

            if ($execution->status !== 'success') {
                $this->dispatch('error', 'Can only restore from successful backups.');
                return;
            }

            if (! $execution->s3_uploaded) {
                $this->dispatch('error', 'Backup not available in S3.');
                return;
            }

            $this->selectedExecution = $execution;
            $this->confirmingRestore = true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restore()
    {
        try {
            $this->authorize('manageBackups', $this->application);

            if (! $this->selectedExecution) {
                throw new \Exception('No backup selected for restore.');
            }

            ApplicationRestoreJob::dispatch(
                application_uuid: $this->application->uuid,
                backup_execution_uuid: $this->selectedExecution->uuid,
            );

            $this->confirmingRestore = false;
            $this->selectedExecution = null;
            $this->dispatch('success', 'Restore job dispatched successfully. This may take a few minutes.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function download($executionId)
    {
        try {
            $this->authorize('manageBackups', $this->application);

            $execution = ScheduledApplicationBackupExecution::findOrFail($executionId);

            if (! $execution->s3_uploaded || ! $execution->filename) {
                $this->dispatch('error', 'Backup not available for download.');
                return;
            }

            $s3 = $this->scheduledBackup->s3;

            if (! $s3) {
                $this->dispatch('error', 'S3 storage not configured.');
                return;
            }

            // Stream download from S3
            $disk = Storage::build([
                'driver' => 's3',
                'key' => $s3->key,
                'secret' => $s3->secret,
                'region' => $s3->region,
                'bucket' => $s3->bucket,
                'endpoint' => $s3->endpoint,
                'use_path_style_endpoint' => true,
                'aws_url' => $s3->awsUrl(),
            ]);

            return $disk->download($execution->filename);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete($executionId)
    {
        try {
            $this->authorize('manageBackups', $this->application);

            $execution = ScheduledApplicationBackupExecution::findOrFail($executionId);

            if ($execution->s3_uploaded && ! $execution->s3_storage_deleted && $this->scheduledBackup->s3) {
                // Delete from S3
                $s3 = $this->scheduledBackup->s3;
                $disk = Storage::build([
                    'driver' => 's3',
                    'key' => $s3->key,
                    'secret' => $s3->secret,
                    'region' => $s3->region,
                    'bucket' => $s3->bucket,
                    'endpoint' => $s3->endpoint,
                    'use_path_style_endpoint' => true,
                    'aws_url' => $s3->awsUrl(),
                ]);

                $disk->delete($execution->filename);
            }

            $execution->delete();

            $this->dispatch('success', 'Backup deleted successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getExecutionsProperty()
    {
        $query = ScheduledApplicationBackupExecution::where('scheduled_application_backup_id', $this->scheduledBackup?->id)
            ->orderBy('created_at', $this->sort === 'date' ? 'desc' : 'asc');

        if ($this->filter !== 'all') {
            $query->where('status', $this->filter);
        }

        return $query->paginate(10);
    }

    public function render()
    {
        return view('livewire.project.application.backup-executions-list', [
            'executions' => $this->executions,
        ]);
    }
}
