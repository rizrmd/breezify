<div>
    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold">Backup Executions</h3>

            <div class="flex items-center gap-2">
                @can('manageBackups', $application)
                    @if ($scheduledBackup && $scheduledBackup->s3_storage_id)
                        <x-forms.button type="button" wire:click="backupNow">
                            Backup Now
                        </x-forms.button>
                    @endif
                @endcan

                <select wire:model.live="filter" class="px-3 py-1 border rounded-sm dark:bg-coolgray-200 dark:border-coolgray-300">
                    <option value="all">All Status</option>
                    <option value="success">Success</option>
                    <option value="failed">Failed</option>
                    <option value="running">Running</option>
                </select>
            </div>
        </div>

        @if (!$scheduledBackup)
            <div class="p-4 text-center text-neutral-500">
                <p>No backup schedule configured. Please create a backup schedule first.</p>
            </div>
        @elseif ($executions->count() === 0)
            <div class="p-4 text-center text-neutral-500">
                <p>No backup executions found.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase bg-neutral-100 dark:bg-coolgray-300 dark:text-neutral-400">
                        <tr>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Size</th>
                            <th class="px-4 py-3">Checksum</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($executions as $execution)
                            <tr class="border-b dark:border-coolgray-300">
                                <td class="px-4 py-3">
                                    @if ($execution->status === 'success')
                                        <span class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded-full dark:bg-green-900 dark:text-green-200">
                                            Success
                                        </span>
                                    @elseif ($execution->status === 'failed')
                                        <span class="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded-full dark:bg-red-900 dark:text-red-200">
                                            Failed
                                        </span>
                                    @else
                                        <span class="px-2 py-1 text-xs font-semibold text-yellow-800 bg-yellow-100 rounded-full dark:bg-yellow-900 dark:text-yellow-200">
                                            Running
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{ $execution->created_at->format('Y-m-d H:i:s') }}
                                    @if ($execution->finished_at)
                                        <br><span class="text-xs text-neutral-500">(took {{ $execution->created_at->diffForHumans($execution->finished_at, true) }})</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($execution->size)
                                        {{ formatBytes($execution->size) }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($execution->checksum)
                                        <span class="font-mono text-xs" title="{{ $execution->checksum }}">
                                            {{ str($execution->checksum)->limit(10, '...') }}
                                        </span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($execution->status === 'success' && $execution->s3_uploaded)
                                            @can('manageBackups', $application)
                                                <x-forms.button
                                                    type="button"
                                                    wire:click="confirmRestore({{ $execution->id }})"
                                                    class="text-sm !py-1 !px-3"
                                                >
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                    </svg>
                                                    Restore
                                                </x-forms.button>

                                                <x-forms.button
                                                    type="button"
                                                    wire:click="download({{ $execution->id }})"
                                                    class="text-sm !py-1 !px-3"
                                                >
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                                    </svg>
                                                    Download
                                                </x-forms.button>
                                            @endcan
                                        @endif

                                        @can('manageBackups', $application)
                                            <x-modal-confirmation
                                                title="Delete Backup?"
                                                buttonTitle="Delete"
                                                submitButtonTitle="Yes, Delete"
                                                wire:confirm="delete({{ $execution->id }})"
                                            >
                                                <p>This will permanently delete this backup. Are you sure?</p>
                                            </x-modal-confirmation>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $executions->links() }}
        @endif

        {{-- Restore Confirmation Modal --}}
        <x-modal-modal
            x-show="{{ $confirmingRestore }}"
            x-cloak
            wire:model="confirmingRestore"
        >
            <div class="p-6">
                <h3 class="text-lg font-semibold mb-4">Restore Application Backup</h3>

                @if ($selectedExecution)
                    <div class="space-y-4">
                        <div class="p-4 bg-neutral-100 dark:bg-coolgray-300 rounded-sm">
                            <p><strong>Date:</strong> {{ $selectedExecution->created_at->format('Y-m-d H:i:s') }}</p>
                            <p><strong>Size:</strong> {{ $selectedExecution->size ? formatBytes($selectedExecution->size) : 'N/A' }}</p>
                            @if ($selectedExecution->checksum)
                                <p><strong>Checksum:</strong> <span class="font-mono text-xs">{{ $selectedExecution->checksum }}</span></p>
                            @endif
                            @if ($selectedExecution->metadata)
                                <p><strong>File Volumes:</strong> {{ data_get($selectedExecution->metadata, 'file_volumes_count', 0) }}</p>
                            @endif
                        </div>

                        <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-sm">
                            <h4 class="font-semibold text-yellow-800 dark:text-yellow-200 mb-2">Warning</h4>
                            <ul class="list-disc list-inside text-sm text-yellow-700 dark:text-yellow-300 space-y-1">
                                <li>This will replace your current application configuration</li>
                                <li>All environment variables will be restored</li>
                                <li>File volumes will be restored from the backup</li>
                                <li>Make sure to create a backup before restoring if needed</li>
                            </ul>
                        </div>

                        <div class="flex justify-end gap-2">
                            <x-forms.button type="button" wire:click="$set('confirmingRestore', false)" class="!bg-neutral-200">
                                Cancel
                            </x-forms.button>
                            <x-forms.button type="button" wire:click="restore" class="text-warning">
                                Confirm Restore
                            </x-forms.button>
                        </div>
                    </div>
                @endif
            </div>
        </x-modal-modal>
    </div>
</div>
