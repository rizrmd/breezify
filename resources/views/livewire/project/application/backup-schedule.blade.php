<div>
    <div class="flex flex-col gap-4 pb-4">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold">Backup Schedule</h3>
            @if ($backup)
                @can('manageBackups', $application)
                    <x-forms.button type="button" wire:click="delete" class="text-error">
                        Delete Schedule
                    </x-forms.button>
                @endcan
            @endif
        </div>

        <form wire:submit='submit' class="flex flex-col gap-3">
            <div class="flex flex-col gap-2">
                <x-forms.input
                    canGate="manageBackups"
                    :canResource="$application"
                    id="description"
                    label="Description"
                    placeholder="Daily application backup"
                    wire:model="description"
                />

                <x-forms.select
                    canGate="manageBackups"
                    :canResource="$application"
                    id="enabled"
                    label="Status"
                    wire:model="enabled"
                >
                    <option value="1">Enabled</option>
                    <option value="0">Disabled</option>
                </x-forms.select>

                <x-forms.input
                    canGate="manageBackups"
                    :canResource="$application"
                    id="frequency"
                    label="Frequency (Cron Expression)"
                    placeholder="0 2 * * *"
                    helper="Run daily at 2 AM. Example: 0 2 * * *"
                    required
                    wire:model="frequency"
                />

                <x-forms.select
                    canGate="manageBackups"
                    :canResource="$application"
                    id="s3_storage_id"
                    label="S3 Storage"
                    wire:model="s3_storage_id"
                >
                    <option value="">Select S3 Storage</option>
                    @foreach ($s3s as $s3)
                        <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                    @endforeach
                </x-forms.select>

                <h4 class="pt-2 font-semibold">Retention Policies (S3)</h4>

                <x-forms.input
                    canGate="manageBackups"
                    :canResource="$application"
                    id="application_backup_retention_amount_s3"
                    label="Keep N Most Recent Backups"
                    type="number"
                    placeholder="0 = unlimited"
                    wire:model="application_backup_retention_amount_s3"
                />

                <x-forms.input
                    canGate="manageBackups"
                    :canResource="$application"
                    id="application_backup_retention_days_s3"
                    label="Delete Backups Older Than N Days"
                    type="number"
                    placeholder="0 = unlimited"
                    wire:model="application_backup_retention_days_s3"
                />

                <x-forms.input
                    canGate="manageBackups"
                    :canResource="$application"
                    id="application_backup_retention_max_storage_s3"
                    label="Max Total Storage (GB)"
                    type="number"
                    step="0.1"
                    placeholder="0 = unlimited"
                    wire:model="application_backup_retention_max_storage_s3"
                />

                <x-forms.button
                    canGate="manageBackups"
                    :canResource="$application"
                    type="submit"
                >
                    {{ $backup ? 'Update' : 'Create' }} Backup Schedule
                </x-forms.button>
            </div>
        </form>
    </div>
</div>
