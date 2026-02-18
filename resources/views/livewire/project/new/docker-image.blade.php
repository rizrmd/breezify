<div x-data x-init="$nextTick(() => { if ($refs.autofocusInput) $refs.autofocusInput.focus(); })">
    <h1>Create a new Application</h1>
    <div class="pb-4">You can deploy an existing Docker Image from any Registry.</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pt-4 pb-1">
            <h2>Docker Image</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <div class="space-y-4">
            <x-forms.input id="imageName" label="Image Name" placeholder="nginx, docker.io/nginx:latest, ghcr.io/user/app:v1.2.3, or nginx:stable@sha256:abc123..."
                helper="Enter the Docker image name with optional registry. You can also paste a complete reference like 'nginx:stable@sha256:abc123...' and the fields below will be auto-filled."
                required autofocus />
            <div class="relative grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-forms.input id="imageTag" label="Tag (optional)" placeholder="latest"
                    helper="Enter a tag like 'latest' or 'v1.2.3'. Leave empty if using SHA256." />
                <div
                    class="absolute left-1/2 top-1/2 transform -translate-x-1/2 -translate-y-1/2 hidden md:flex items-center justify-center z-10">
                    <div
                        class="px-2 py-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-300 rounded text-xs font-bold text-neutral-500 dark:text-neutral-400">
                        OR
                    </div>
                </div>
                <x-forms.input id="imageSha256" label="SHA256 Digest (optional)"
                    placeholder="59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0"
                    helper="Enter only the 64-character hex digest (without 'sha256:' prefix)" />
            </div>
            <div class="pt-6">
                <h2>Resource Limits</h2>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        <label class="flex items-center gap-2 text-sm font-medium">
                            Number of CPUs
                            <span class="text-xs text-neutral-500">(max {{ $maxCpus }})</span>
                        </label>
                        <div class="flex items-center gap-4">
                            <input type="range" min="0" max="{{ $maxCpus }}" step="0.1" wire:model.live="limitsCpus"
                                class="w-full accent-purple-500" required />
                            <div class="w-24 text-right text-sm">
                                {{ number_format((float) $limitsCpus, 1) }}
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="flex items-center gap-2 text-sm font-medium">
                            Maximum Memory Limit (GB)
                            <span class="text-xs text-neutral-500">(max {{ $maxMemoryGb }})</span>
                        </label>
                        <div class="flex items-center gap-4">
                            <input type="range" min="0" max="{{ $maxMemoryGb }}" step="0.1" wire:model.live="limitsMemory"
                                class="w-full accent-purple-500" required />
                            <div class="w-24 text-right text-sm">
                                {{ number_format((float) $limitsMemory, 1) }}
                            </div>
                        </div>
                    </div>
                </div>
        </div>
    </form>
</div>
