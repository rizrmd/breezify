<?php

namespace App\Livewire\Project\Shared;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ResourceLimits extends Component
{
    use AuthorizesRequests;

    public $resource;

    // Explicit properties
    public ?string $limitsCpus = null;

    public ?string $limitsCpuset = null;

    public ?int $limitsCpuShares = null;

    public string $limitsMemory;

    public string $limitsMemorySwap;

    public int $limitsMemorySwappiness;

    public string $limitsMemoryReservation;

    public float $maxCpus = 1.0;

    public float $maxMemoryGb = 1.0;

    public ?float $limitsCpusValue = null;

    public ?float $limitsMemoryValue = null;

    protected $validationAttributes = [
        'limitsMemoryValue' => 'memory',
        'limitsMemorySwap' => 'swap',
        'limitsMemorySwappiness' => 'swappiness',
        'limitsMemoryReservation' => 'reservation',
        'limitsCpusValue' => 'cpus',
        'limitsCpuset' => 'cpuset',
        'limitsCpuShares' => 'cpu shares',
    ];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->limitsCpus = $this->formatCpuLimit($this->limitsCpusValue);
            $this->limitsMemory = $this->formatMemoryLimit($this->limitsMemoryValue);
            // Sync TO model (before save)
            $this->resource->limits_cpus = $this->limitsCpus;
            $this->resource->limits_cpuset = $this->limitsCpuset;
            $this->resource->limits_cpu_shares = $this->limitsCpuShares;
            $this->resource->limits_memory = $this->limitsMemory;
            $this->resource->limits_memory_swap = $this->limitsMemorySwap;
            $this->resource->limits_memory_swappiness = $this->limitsMemorySwappiness;
            $this->resource->limits_memory_reservation = $this->limitsMemoryReservation;
        } else {
            // Sync FROM model (on load/refresh)
            $this->limitsCpus = $this->resource->limits_cpus;
            $this->limitsCpuset = $this->resource->limits_cpuset;
            $this->limitsCpuShares = $this->resource->limits_cpu_shares;
            $this->limitsMemory = $this->resource->limits_memory;
            $this->limitsMemorySwap = $this->resource->limits_memory_swap;
            $this->limitsMemorySwappiness = $this->resource->limits_memory_swappiness;
            $this->limitsMemoryReservation = $this->resource->limits_memory_reservation;
            $this->limitsCpusValue = $this->parseCpuLimit($this->limitsCpus);
            $this->limitsMemoryValue = $this->parseMemoryToGb($this->limitsMemory);
        }
    }

    public function mount()
    {
        $this->resolveResourceCaps();
        $this->syncData(false);
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);
            if (is_null($this->limitsMemoryValue)) {
                $this->limitsMemoryValue = 0.0;
            }
            if (! $this->limitsMemorySwap) {
                $this->limitsMemorySwap = '0';
            }
            if (is_null($this->limitsMemorySwappiness)) {
                $this->limitsMemorySwappiness = 60;
            }
            if (! $this->limitsMemoryReservation) {
                $this->limitsMemoryReservation = '0';
            }
            if (is_null($this->limitsCpusValue)) {
                $this->limitsCpusValue = 0.0;
            }
            if ($this->limitsCpuset === '') {
                $this->limitsCpuset = null;
            }
            if (is_null($this->limitsCpuShares)) {
                $this->limitsCpuShares = 1024;
            }

            $this->validate($this->rules());
            $this->syncData(true);
            $this->resource->save();
            $this->dispatch('success', 'Resource limits updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function rules(): array
    {
        return [
            'limitsMemoryValue' => 'required|numeric|min:0|max:'.$this->maxMemoryGb,
            'limitsMemorySwap' => 'required|string',
            'limitsMemorySwappiness' => 'required|integer|min:0|max:100',
            'limitsMemoryReservation' => 'required|string',
            'limitsCpusValue' => 'required|numeric|min:0|max:'.$this->maxCpus,
            'limitsCpuset' => 'nullable',
            'limitsCpuShares' => 'nullable',
        ];
    }

    private function resolveResourceCaps(): void
    {
        $server = $this->resource->destination?->server;
        if (! $server) {
            return;
        }
        $limits = resolve_server_resource_limits($server);
        $this->maxCpus = (float) data_get($limits, 'cpus', 1);
        $this->maxMemoryGb = (float) data_get($limits, 'memory_gb', 1);
    }

    private function parseCpuLimit(?string $value): float
    {
        if (blank($value)) {
            return 0.0;
        }

        return (float) $value;
    }

    private function parseMemoryToGb(?string $value): float
    {
        if (blank($value)) {
            return 0.0;
        }

        $normalized = strtolower(trim($value));
        if (is_numeric($normalized)) {
            return (float) $normalized;
        }

        if (str_ends_with($normalized, 'g')) {
            return (float) rtrim($normalized, 'g');
        }

        if (str_ends_with($normalized, 'm')) {
            return (float) rtrim($normalized, 'm') / 1024;
        }

        if (str_ends_with($normalized, 'k')) {
            return (float) rtrim($normalized, 'k') / 1024 / 1024;
        }

        return (float) $normalized;
    }

    private function formatCpuLimit(?float $value): string
    {
        if (is_null($value)) {
            return '0';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function formatMemoryLimit(?float $value): string
    {
        if (is_null($value) || $value <= 0) {
            return '0';
        }

        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted.'g';
    }
}
