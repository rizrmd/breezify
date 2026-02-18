<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">You can deploy a simple Dockerfile, without Git.</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pb-1">
            <h2>Dockerfile</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <x-forms.textarea useMonacoEditor monacoEditorLanguage="dockerfile" rows="20" id="dockerfile" autofocus
            placeholder='FROM nginx
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
'></x-forms.textarea>
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
