<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2 ">
            <h2>Resource Limits</h2>
            <x-forms.button canGate="update" :canResource="$resource" type='submit'>Save</x-forms.button>
        </div>
        <div class="">Limit your container resources by CPU & memory.</div>
        <h3 class="pt-4">Limit CPUs</h3>
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2">
                <label class="flex items-center gap-2 text-sm font-medium">
                    Number of CPUs
                    <span class="text-xs text-neutral-500">(max {{ $maxCpus }})</span>
                </label>
                <div class="flex items-center gap-4">
                    <input type="range" min="0" max="{{ $maxCpus }}" step="0.1" wire:model.live="limitsCpusValue"
                        class="w-full accent-purple-500" />
                    <div class="w-24 text-right text-sm">
                        {{ number_format($limitsCpusValue ?? 0, 1) }}
                    </div>
                </div>
                <div class="text-xs text-neutral-500">
                    0 means use all CPUs. Floating point number, like 0.2 or 1.5.
                </div>
            </div>
            <details class="rounded-lg border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                <summary class="cursor-pointer font-medium text-neutral-700 dark:text-neutral-200">Advanced CPU options</summary>
                <div class="mt-4 flex gap-2">
                    <x-forms.input canGate="update" :canResource="$resource" placeholder="0-2"
                        helper="Empty means, use all CPU sets. 0-2 will use CPU 0, CPU 1 and CPU 2. More info <a class='underline dark:text-white'  target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-share-constraint'>here</a>."
                        label="CPU sets to use" id="limitsCpuset" />
                    <x-forms.input canGate="update" :canResource="$resource" placeholder="1024"
                        helper="More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-share-constraint'>here</a>."
                        label="CPU Weight" id="limitsCpuShares" />
                </div>
            </details>
        </div>
        <h3 class="pt-4">Limit Memory</h3>
        <div class="flex flex-col gap-2">
            <div class="flex flex-col gap-2">
                <label class="flex items-center gap-2 text-sm font-medium">
                    Maximum Memory Limit (GB)
                    <span class="text-xs text-neutral-500">(max {{ $maxMemoryGb }})</span>
                </label>
                <div class="flex items-center gap-4">
                    <input type="range" min="0" max="{{ $maxMemoryGb }}" step="0.1" wire:model.live="limitsMemoryValue"
                        class="w-full accent-purple-500" />
                    <div class="w-24 text-right text-sm">
                        {{ number_format($limitsMemoryValue ?? 0, 1) }}
                    </div>
                </div>
                <div class="text-xs text-neutral-500">
                    0 means use all memory. Values are stored as gigabytes.
                </div>
            </div>
            <details class="rounded-lg border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                <summary class="cursor-pointer font-medium text-neutral-700 dark:text-neutral-200">Advanced memory options</summary>
                <div class="mt-4 flex flex-col gap-2">
                    <div class="flex gap-2">
                        <x-forms.input canGate="update" :canResource="$resource"
                            helper="Examples: 69b (byte) or 420k (kilobyte) or 1337m (megabyte) or 1g (gigabyte).<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#mem_reservation'>here</a>."
                            label="Soft Memory Limit" id="limitsMemoryReservation" />
                        <x-forms.input canGate="update" :canResource="$resource"
                            helper="0-100.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#mem_swappiness'>here</a>."
                            type="number" min="0" max="100" label="Swappiness"
                            id="limitsMemorySwappiness" />
                    </div>
                    <div class="flex gap-2">
                        <x-forms.input canGate="update" :canResource="$resource"
                            helper="Examples:69b (byte) or 420k (kilobyte) or 1337m (megabyte) or 1g (gigabyte).<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#memswap_limit'>here</a>."
                            label="Maximum Swap Limit" id="limitsMemorySwap" />
                    </div>
                </div>
            </details>
        </div>
    </form>
</div>
