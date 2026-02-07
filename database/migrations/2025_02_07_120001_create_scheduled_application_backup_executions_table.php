<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_application_backup_executions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->enum('status', ['success', 'failed', 'running'])->default('running');
            $table->longText('message')->nullable();
            $table->text('size')->nullable();
            $table->text('filename')->nullable();
            $table->text('checksum')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('s3_uploaded')->default(false);
            $table->boolean('s3_storage_deleted')->default(false);
            $table->foreignId('scheduled_application_backup_id');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_application_backup_executions');
    }
};
