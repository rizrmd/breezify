<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_application_backups', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('save_s3')->default(true);
            $table->string('frequency');
            $table->morphs('application');
            $table->foreignId('s3_storage_id')->nullable();
            $table->foreignId('team_id');

            // Retention policies (S3 only)
            $table->integer('application_backup_retention_amount_s3')->default(0);
            $table->integer('application_backup_retention_days_s3')->default(0);
            $table->decimal('application_backup_retention_max_storage_s3', 17, 7)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_application_backups');
    }
};
