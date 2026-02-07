<?php

namespace App\Livewire\Project\Application;

use App\Models\LocalFileVolume;
use App\Models\ScheduledApplicationBackup;
use App\Models\S3Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class BackupSchedule extends Component
{
    use AuthorizesRequests;

    public $application;

    public $s3s;

    public ?ScheduledApplicationBackup $backup = null;

    public $frequency = '0 * * * *';

    public $s3_storage_id = null;

    public $application_backup_retention_amount_s3 = 0;

    public $application_backup_retention_days_s3 = 0;

    public $application_backup_retention_max_storage_s3 = 0;

    public $description = null;

    public $enabled = true;

    protected $rules = [
        'frequency' => 'required',
        's3_storage_id' => 'nullable|exists:s3_storages,id',
        'application_backup_retention_amount_s3' => 'nullable|integer|min:0',
        'application_backup_retention_days_s3' => 'nullable|integer|min:0',
        'application_backup_retention_max_storage_s3' => 'nullable|numeric|min:0',
        'description' => 'nullable|string',
        'enabled' => 'boolean',
    ];

    public function mount()
    {
        $this->s3s = currentTeam()->s3s;
        $this->backup = $this->application->scheduledBackups()->first();

        if ($this->backup) {
            $this->frequency = $this->backup->frequency;
            $this->s3_storage_id = $this->backup->s3_storage_id;
            $this->application_backup_retention_amount_s3 = $this->backup->application_backup_retention_amount_s3;
            $this->application_backup_retention_days_s3 = $this->backup->application_backup_retention_days_s3;
            $this->application_backup_retention_max_storage_s3 = (float) $this->backup->application_backup_retention_max_storage_s3;
            $this->description = $this->backup->description;
            $this->enabled = $this->backup->enabled;
        }
    }

    public function submit()
    {
        try {
            $this->authorize('manageBackups', $this->application);

            $this->validate();

            if ($this->backup) {
                $this->backup->update([
                    'frequency' => $this->frequency,
                    's3_storage_id' => $this->s3_storage_id ?: null,
                    'application_backup_retention_amount_s3' => $this->application_backup_retention_amount_s3,
                    'application_backup_retention_days_s3' => $this->application_backup_retention_days_s3,
                    'application_backup_retention_max_storage_s3' => $this->application_backup_retention_max_storage_s3,
                    'description' => $this->description,
                    'enabled' => $this->enabled,
                ]);

                $this->dispatch('success', 'Backup schedule updated successfully.');
            } else {
                $this->backup = ScheduledApplicationBackup::create([
                    'uuid' => str()->uuid(),
                    'application_id' => $this->application->id,
                    'application_type' => get_class($this->application),
                    'frequency' => $this->frequency,
                    's3_storage_id' => $this->s3_storage_id ?: null,
                    'team_id' => currentTeam()->id,
                    'application_backup_retention_amount_s3' => $this->application_backup_retention_amount_s3,
                    'application_backup_retention_days_s3' => $this->application_backup_retention_days_s3,
                    'application_backup_retention_max_storage_s3' => $this->application_backup_retention_max_storage_s3,
                    'description' => $this->description,
                    'enabled' => $this->enabled,
                    'save_s3' => true,
                ]);

                $this->dispatch('success', 'Backup schedule created successfully.');
            }

            $this->dispatch('refreshBackupExecutions');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('manageBackups', $this->application);

            if ($this->backup) {
                $this->backup->delete();
                $this->backup = null;
                $this->dispatch('success', 'Backup schedule deleted successfully.');
                $this->dispatch('refreshBackupExecutions');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.backup-schedule');
    }
}
