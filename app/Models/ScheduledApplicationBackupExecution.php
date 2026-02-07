<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledApplicationBackupExecution extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            's3_uploaded' => 'boolean',
            's3_storage_deleted' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function scheduledApplicationBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledApplicationBackup::class);
    }
}
