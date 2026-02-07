# Application Backup & Restore - Developer Guide

## Quick Start

### Installation

1. **Run migrations:**
   ```bash
   php artisan migrate
   ```

2. **Clear caches:**
   ```bash
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   ```

3. **Verify installation:**
   - Navigate to an Application page
   - Click "Persistent Storage" tab
   - Verify "Backups" tab appears (for Applications only)

---

## Architecture Overview

### Component Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                         Frontend                             │
├─────────────────────────────────────────────────────────────┤
│  Storage.blade.php (Backups Tab)                            │
│  ├── BackupSchedule.blade.php (Livewire)                    │
│  └── BackupExecutionsList.blade.php (Livewire)              │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                       Backend Logic                          │
├─────────────────────────────────────────────────────────────┤
│  ApplicationBackupJob (Queue Job)                           │
│  ├── ConfigurationGenerator (Export Config)                 │
│  ├── FileVolume Archiver (Tar Creation)                     │
│  ├── S3 Uploader (Helper Container)                         │
│  └── Retention Policy (Delete Old Backups)                  │
│                                                              │
│  ApplicationRestoreJob (Queue Job)                          │
│  ├── S3 Downloader (Helper Container)                       │
│  ├── Checksum Validator                                     │
│  ├── Conflict Detector                                      │
│  └── Configuration Restorer                                  │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                         Data Layer                           │
├─────────────────────────────────────────────────────────────┤
│  ScheduledApplicationBackup (Model)                         │
│  ├── MorphTo: Application                                   │
│  ├── BelongsTo: S3Storage                                   │
│  └── HasMany: Executions                                    │
│                                                              │
│  ScheduledApplicationBackupExecution (Model)                │
│  ├── BelongsTo: ScheduledBackup                             │
│  └── Fields: status, size, checksum, metadata               │
└─────────────────────────────────────────────────────────────┘
```

### Data Flow

#### Backup Process
```
1. User Action (UI)
   ↓
2. BackupSchedule Component
   ↓
3. ApplicationBackupJob::dispatch()
   ↓
4. Job Execution (Queue Worker)
   ├── 4a. Export Configuration (ConfigurationGenerator)
   ├── 4b. Archive File Volumes (tar.gz)
   ├── 4c. Create Combined Tarball
   ├── 4d. Calculate SHA256 Checksum
   ├── 4e. Upload to S3 (Helper Container)
   └── 4f. Apply Retention Policies
   ↓
5. Update Execution Record (Success/Failed)
   ↓
6. Send Notification (Email/Slack/etc.)
```

#### Restore Process
```
1. User Action (UI)
   ↓
2. BackupExecutionsList Component
   ↓
3. ApplicationRestoreJob::dispatch()
   ↓
4. Job Execution (Queue Worker)
   ├── 4a. Download from S3 (Helper Container)
   ├── 4b. Extract Tarball
   ├── 4c. Validate Checksum
   ├── 4d. Check Conflicts
   ├── 4e. Start DB Transaction
   ├── 4f. Restore Configuration
   ├── 4g. Restore Environment Variables
   ├── 4h. Restore File Volumes
   ├── 4i. Commit Transaction
   └── 4j. Cleanup Temp Files
   ↓
5. Success/Error Notification
```

---

## File Structure Reference

### Models
```
app/Models/
├── Application.php (modified)
│   └── scheduledBackups(): MorphMany
├── ScheduledApplicationBackup.php (new)
│   ├── application(): MorphTo
│   ├── s3(): BelongsTo
│   ├── executions(): HasMany
│   └── server(): Helper method
└── ScheduledApplicationBackupExecution.php (new)
    └── scheduledApplicationBackup(): BelongsTo
```

### Jobs
```
app/Jobs/
├── ApplicationBackupJob.php (new)
│   ├── collectApplicationConfiguration()
│   ├── archiveFileVolumes()
│   ├── createBackupTarball()
│   ├── calculateChecksum()
│   ├── calculateBackupSize()
│   └── uploadToS3()
└── ApplicationRestoreJob.php (new)
    ├── downloadFromS3()
    ├── extractAndValidate()
    ├── checkConflicts()
    ├── restoreConfiguration()
    ├── restoreEnvironmentVariables()
    └── restoreFileVolumes()
```

### Helpers
```
bootstrap/helpers/
└── applications.php (modified)
    ├── removeOldBackupsFromS3ForApplications()
    └── deleteOldApplicationBackupsFromS3()
```

### Livewire Components
```
app/Livewire/Project/Application/
├── BackupSchedule.php (new)
│   ├── submit() - Create/Update schedule
│   └── delete() - Delete schedule
└── BackupExecutionsList.php (new)
    ├── backupNow() - Manual backup
    ├── download() - Download from S3
    ├── confirmRestore() - Show restore dialog
    ├── restore() - Execute restore
    └── delete() - Delete backup
```

### Views
```
resources/views/
├── livewire/project/service/storage.blade.php (modified)
│   └── Added "Backups" tab
├── livewire/project/application/
│   ├── backup-schedule.blade.php (new)
│   └── backup-executions-list.blade.php (new)
└── emails/
    ├── application-backup-success.blade.php (new)
    ├── application-backup-failed.blade.php (new)
    └── application-backup-success-with-s3-warning.blade.php (new)
```

---

## API Reference

### Model: ScheduledApplicationBackup

#### Properties
```php
$id                              // int
$uuid                            // string (unique)
$description                     // string|null
$enabled                         // boolean
$save_s3                         // boolean
$frequency                       // string (cron expression)
$application_id                  // int (polymorphic)
$application_type                // string (polymorphic)
$s3_storage_id                   // int|null
$team_id                         // int
$application_backup_retention_amount_s3    // int
$application_backup_retention_days_s3      // int
$application_backup_retention_max_storage_s3 // decimal
```

#### Methods
```php
// Relationships
$backup->team()                  // BelongsTo Team
$backup->application()           // MorphTo Application
$backup->s3()                    // BelongsTo S3Storage
$backup->executions()            // HasMany ScheduledApplicationBackupExecution
$backup->latest_log()            // HasOne latest execution

// Scopes
ScheduledApplicationBackup::ownedByCurrentTeam()      // Query current team's backups
ScheduledApplicationBackup::ownedByCurrentTeamAPI($teamId) // Query by team ID

// Helper
$backup->server()                // Get server from application
```

### Model: ScheduledApplicationBackupExecution

#### Properties
```php
$id                              // int
$uuid                            // string (unique)
$status                          // enum: 'success'|'failed'|'running'
$message                         // string|null
$size                            // string|null (bytes)
$filename                        // string|null (S3 path)
$checksum                        // string|null (SHA256)
$metadata                        // array|null
$s3_uploaded                     // boolean
$s3_storage_deleted              // boolean
$scheduled_application_backup_id // int
$finished_at                     // Carbon|null
```

#### Methods
```php
// Relationships
$execution->scheduledApplicationBackup()  // BelongsTo ScheduledApplicationBackup
```

### Job: ApplicationBackupJob

#### Constructor
```php
new ApplicationBackupJob($scheduledBackup);
```

#### Properties
```php
$backup                          // ScheduledApplicationBackup
$application                     // Application
$server                          // Server
$s3                              // S3Storage|null
$backup_log_uuid                 // string (execution UUID)
$backup_location                 // string (temp file path)
$size                            // int (bytes)
$backup_output                   // string|null
$error_output                    // string|null
$s3_uploaded                     // boolean
$timeout                         // int (default 3600)
```

#### Methods (Private)
```php
collectApplicationConfiguration() // string JSON
archiveFileVolumes()              // string|null tarball path
createBackupTarball()             // void
calculateChecksum()               // string
calculateBackupSize()             // int
uploadToS3()                      // void
cleanupLocalBackup()              // void
sendNotification()                // void
```

### Job: ApplicationRestoreJob

#### Constructor
```php
new ApplicationRestoreJob($application_uuid, $backup_execution_uuid, $restore_uuid);
```

#### Properties
```php
$application_uuid                // string
$backup_execution_uuid           // string
$restore_uuid                    // string (optional)
$application                     // Application
$backupExecution                 // ScheduledApplicationBackupExecution
$server                          // Server
$s3                              // S3Storage|null
$restore_location                // string (temp file path)
$backupConfig                    // array
$timeout                         // int (default 3600)
```

#### Methods (Private)
```php
downloadFromS3()                 // void
extractAndValidate()             // void
checkConflicts()                 // array
restoreConfiguration()           // void
restoreEnvironmentVariables()    // void
restoreFileVolumes()             // void
cleanupRestoreFiles()            // void
```

---

## Common Tasks

### Create a Backup Schedule via Code

```php
use App\Models\Application;
use App\Models\ScheduledApplicationBackup;
use App\Models\S3Storage;

$application = Application::where('uuid', 'app-uuid')->first();
$s3 = S3Storage::where('name', 'My S3')->first();

$backup = ScheduledApplicationBackup::create([
    'uuid' => str()->uuid(),
    'description' => 'Daily midnight backup',
    'enabled' => true,
    'save_s3' => true,
    'frequency' => '0 0 * * *', // Daily at midnight
    'application_id' => $application->id,
    'application_type' => Application::class,
    's3_storage_id' => $s3->id,
    'team_id' => $application->team()->first()->id,
    'application_backup_retention_amount_s3' => 7,
    'application_backup_retention_days_s3' => 30,
    'application_backup_retention_max_storage_s3' => 10.0,
]);
```

### Trigger Manual Backup via Code

```php
use App\Jobs\ApplicationBackupJob;
use App\Models\ScheduledApplicationBackup;

$backup = ScheduledApplicationBackup::where('uuid', 'backup-uuid')->first();
ApplicationBackupJob::dispatch($backup);
```

### Restore from Backup via Code

```php
use App\Jobs\ApplicationRestoreJob;
use App\Models\Application;
use App\Models\ScheduledApplicationBackupExecution;

$application = Application::where('uuid', 'app-uuid')->first();
$execution = ScheduledApplicationBackupExecution::where('uuid', 'execution-uuid')->first();

ApplicationRestoreJob::dispatch(
    application_uuid: $application->uuid,
    backup_execution_uuid: $execution->uuid,
);
```

### List All Backups for an Application

```php
use App\Models\Application;

$application = Application::where('uuid', 'app-uuid')->first();

// Get scheduled backup
$scheduledBackup = $application->scheduledBackups()->first();

if ($scheduledBackup) {
    // Get executions with pagination
    $executions = $scheduledBackup->executions()->paginate(10);

    foreach ($executions as $execution) {
        echo "Status: {$execution->status}\n";
        echo "Size: " . formatBytes($execution->size) . "\n";
        echo "Created: {$execution->created_at}\n";
    }
}
```

### Apply Retention Policy Manually

```php
use App\Models\ScheduledApplicationBackup;

$backup = ScheduledApplicationBackup::where('uuid', 'backup-uuid')->first();

// This will apply all three retention policies and delete old backups
removeOldBackupsFromS3ForApplications($backup);
```

---

## Configuration Generator

The backup system uses the existing `ConfigurationGenerator` service to export complete application configuration.

### What Gets Exported

```php
use App\Services\ConfigurationGenerator;

$configGenerator = new ConfigurationGenerator($application);
$configArray = $configGenerator->toArray();

// Returns:
[
    'id' => $application->id,
    'name' => $application->name,
    'uuid' => $application->uuid,
    'description' => $application->description,

    'coolify_details' => [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination_type' => $application->destination_type,
        'destination_id' => $application->destination_id,
        // ... more details
    ],

    'post_deployment_command' => $application->post_deployment_command,
    'pre_deployment_command' => $application->pre_deployment_command,

    'build' => [
        'type' => $application->build_pack,
        'static_image' => $application->static_image,
        'base_directory' => $application->base_directory,
        'publish_directory' => $application->publish_directory,
        'dockerfile' => $application->dockerfile,
        'dockerfile_location' => $application->dockerfile_location,
        'docker_compose' => $application->docker_compose,
        'install_command' => $application->install_command,
        'build_command' => $application->build_command,
        'start_command' => $application->start_command,
        // ... more build settings
    ],

    'source' => [
        'git_repository' => $application->git_repository,
        'git_branch' => $application->git_branch,
        'git_commit_sha' => $application->git_commit_sha,
    ],

    'domains' => [
        'fqdn' => $application->fqdn,
        'ports_exposes' => $application->ports_exposes,
        'ports_mappings' => $application->ports_mappings,
    ],

    'environment_variables' => [
        'production' => [
            ['key' => 'ENV_VAR', 'value' => 'value', 'is_preview' => false],
            // ... more variables
        ],
        'preview' => [
            ['key' => 'ENV_VAR', 'value' => 'value', 'is_preview' => true],
            // ... more variables
        ],
    ],

    'settings' => $application->settings,
    'limits' => $application->getLimits(),

    'health_check' => [
        'health_check_enabled' => $application->health_check_enabled,
        'health_check_path' => $application->health_check_path,
        'health_check_port' => $application->health_check_port,
        // ... more health check settings
    ],

    'webhooks_secrets' => [
        'manual_webhook_secret_github' => $application->manual_webhook_secret_github,
        // ... more secrets
    ],

    'swarm' => [
        'swarm_replicas' => $application->swarm_replicas,
        'swarm_placement_constraints' => $application->swarm_placement_constraints,
    ],

    // Added by backup system:
    'file_volumes' => [
        [
            'uuid' => $volume->uuid,
            'fs_path' => $volume->fs_path,
            'mount_path' => $volume->mount_path,
            'is_directory' => $volume->is_directory,
        ],
        // ... more volumes
    ],

    'backup_metadata' => [
        'application_uuid' => $application->uuid,
        'backup_created_at' => now()->toIso8601String(),
        'file_volumes_count' => count($file_volumes),
    ],
]
```

---

## Retention Policies

### How They Work

Three independent policies can be configured. Any or all can be active simultaneously.

#### Policy 1: Keep N Most Recent Backups
```php
$backup->application_backup_retention_amount_s3 = 7;
// Keeps only the 7 most recent successful backups
```

#### Policy 2: Delete Backups Older Than N Days
```php
$backup->application_backup_retention_days_s3 = 30;
// Deletes any backup older than 30 days
```

#### Policy 3: Max Total Storage
```php
$backup->application_backup_retention_max_storage_s3 = 10.0; // GB
// Deletes oldest backups until total size < 10 GB
```

### Execution Order

When retention policies are applied:

1. **Collect all backups** that need deletion consideration
2. **Apply policy 1** → Mark excess backups for deletion
3. **Apply policy 2** → Mark old backups for deletion
4. **Apply policy 3** → Mark oldest backups until under limit
5. **Union of all marked backups** → Delete from S3
6. **Update execution records** → Set `s3_storage_deleted = true`

### Example Scenario

```
Configuration:
- Keep last 5 backups
- Delete after 14 days
- Max 10 GB total

Current backups:
1. Backup A: 2 GB, 2 days old
2. Backup B: 2 GB, 5 days old
3. Backup C: 2 GB, 10 days old
4. Backup D: 2 GB, 16 days old
5. Backup E: 2 GB, 20 days old
6. Backup F: 2 GB, 25 days old

Policy 1 (keep 5): Mark Backup F for deletion
Policy 2 (14 days): Mark Backups D, E, F for deletion
Policy 3 (10 GB): Keep A, B, C, D (8 GB), Mark E, F for deletion

Result: Delete Backups E and F from S3
```

---

## Troubleshooting Guide

### Debug Mode

Enable detailed logging in `.env`:

```env
LOG_LEVEL=debug
```

### Check Queue Status

```bash
# Check Horizon status
php artisan horizon:status

# Check queue worker
php artisan queue:work --verbose

# List failed jobs
php artisan queue:failed
```

### View Logs

```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Scheduled errors
tail -f storage/logs/scheduled-errors.log

# Horizon logs
tail -f storage/logs/horizon.log
```

### Common Issues

#### 1. Backup Stuck on "Running"

**Symptoms:** Execution status never changes from "running"

**Solutions:**
- Check if Horizon/queue worker is running
- Check server has sufficient disk space
- Check for network connectivity to S3
- Review queue logs for errors

```bash
# Restart Horizon
php artisan horizon:terminate
php artisan horizon

# Clear stuck jobs
php artisan queue:flush
```

#### 2. S3 Upload Fails

**Symptoms:** Status shows "success" but `s3_uploaded = false`

**Solutions:**
- Verify S3 credentials are correct
- Check S3 bucket exists and is accessible
- Verify bucket permissions (list, put, delete)
- Check endpoint URL is correct
- Review execution message for specific error

```php
// Test S3 connection manually
$s3 = S3Storage::find(1);
$disk = Storage::build([
    'driver' => 's3',
    'key' => $s3->key,
    'secret' => $s3->secret,
    'region' => $s3->region,
    'bucket' => $s3->bucket,
    'endpoint' => $s3->endpoint,
    'use_path_style_endpoint' => true,
]);

$files = $disk->files('/');
print_r($files);
```

#### 3. Restore Fails

**Symptoms:** Restore job fails or doesn't complete

**Solutions:**
- Verify checksum matches (S3 download may be corrupted)
- Check application has sufficient disk space
- Verify file volume paths exist and are writable
- Check for configuration conflicts
- Review execution logs

```bash
# Manually verify checksum
sha256sum backup-file.tar.gz
# Compare with execution.checksum
```

#### 4. Retention Policy Not Working

**Symptoms:** Old backups not being deleted

**Solutions:**
- Verify at least one policy value is > 0
- Manually trigger backup to apply retention
- Check S3 bucket for files
- Verify `s3_storage_deleted` flag is set

```php
// Manually apply retention
$backup = ScheduledApplicationBackup::find(1);
removeOldBackupsFromS3ForApplications($backup);

// Check results
$deleted = $backup->executions()->where('s3_storage_deleted', true)->get();
echo "Deleted: " . $deleted->count() . " backups\n";
```

#### 5. Backups Tab Not Showing

**Symptoms:** Backups tab doesn't appear in UI

**Solutions:**
- Verify resource is an Application (not Database)
- Clear browser cache
- Clear view cache
- Check Livewire component errors

```bash
# Clear caches
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

---

## Performance Optimization

### For Large Applications

#### 1. Optimize File Volume Count
```php
// Limit file volumes to speed up backups
$maxVolumes = 100;
$volumes = $application->fileStorages()->take($maxVolumes)->get();
```

#### 2. Increase Job Timeout
```php
// In ApplicationBackupJob
public $timeout = 7200; // 2 hours instead of 1 hour
```

#### 3. Use Queue Priority
```php
// Dispatch to high-priority queue
ApplicationBackupJob::dispatch($backup)->onQueue('high');
```

#### 4. Compress Backups
```php
// Use higher compression (smaller size, slower)
$tarball = "tar -czf {$backup_location} ...";
// Or lower compression (faster, larger size)
$tarball = "tar -c --use-compress-program='gzip -1' -f {$backup_location} ...";
```

### Database Optimization

```sql
-- Add indexes for better query performance
CREATE INDEX idx_executions_status ON scheduled_application_backup_executions(status);
CREATE INDEX idx_executions_created_at ON scheduled_application_backup_executions(created_at DESC);
CREATE INDEX idx_backups_enabled ON scheduled_application_backups(enabled);
```

---

## Security Best Practices

### 1. S3 Credentials
- ✅ Credentials are encrypted in database
- ✅ Never logged or exposed in error messages
- ✅ Escaped before passing to shell commands

### 2. Environment Variables
- ✅ Stay encrypted through backup/restore cycle
- ✅ Never visible in logs
- ✅ Proper access controls

### 3. Path Validation
- ✅ All paths validated with `validateShellSafePath()`
- ✅ All paths escaped with `escapeshellarg()`
- ✅ Prevents command injection

### 4. Authorization
- ✅ `@can('manageBackups', $application)` checks
- ✅ Team-scoped queries
- ✅ Policy-based access control

### 5. Integrity
- ✅ SHA256 checksums for all backups
- ✅ Verified before restore
- ✅ Corruption detection

---

## Extending the System

### Add New Notification Channel

Edit `app/Notifications/Application/BackupSuccess.php`:

```php
public function toCustomChannel(): CustomMessage
{
    return new CustomMessage(
        title: 'Backup successful',
        message: "Application {$this->application->name} backed up successfully",
    );
}
```

### Add Custom Retention Policy

Edit `bootstrap/helpers/applications.php`:

```php
function deleteOldApplicationBackupsFromS3($backup)
{
    // ... existing policies ...

    // Custom policy: Keep backups from last day of month
    $lastDayBackups = $executions->filter(function ($execution) {
        $day = $execution->created_at->day;
        $lastDay = $execution->created_at->endOfMonth()->day;
        return $day === $lastDay;
    });

    // Exclude from deletion
    $backupsToDelete = $backupsToDelete->diff($lastDayBackups);

    // ... rest of function ...
}
```

### Add Backup Pre/Post Hooks

Create a service provider:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Jobs\ApplicationBackupJob;

class BackupHookServiceProvider extends ServiceProvider
{
    public function boot()
    {
        ApplicationBackupJob::saving(function ($backup) {
            // Pre-backup hook
            Log::info('Backup starting', ['app' => $backup->application->name]);
        });

        ApplicationBackupJob::saved(function ($backup) {
            // Post-backup hook
            Log::info('Backup completed', ['app' => $backup->application->name]);
        });
    }
}
```

---

## Contributing

### Running Tests

```bash
# Run all unit tests (no database required)
./vendor/bin/pest tests/Unit

# Run specific test file
./vendor/bin/pest tests/Unit/ApplicationBackup

# Run with coverage
./vendor/bin/pest --coverage
```

### Code Style

```bash
# Format code
./vendor/bin/pint

# Check style
./vendor/bin/pint --test
```

### Static Analysis

```bash
# Run PHPStan
./vendor/bin/phpstan analyse app/Jobs/ApplicationBackupJob.php
```

---

## Additional Resources

### Documentation Files
- `BACKUP_IMPLEMENTATION_SUMMARY.md` - Initial implementation details
- `BACKUP_IMPLEMENTATION_COMPLETE.md` - Complete user guide
- `DEVELOPER_GUIDE_BACKUPS.md` - This file

### Code Reference
- `app/Services/ConfigurationGenerator.php` - Configuration export logic
- `app/Jobs/DatabaseBackupJob.php` - Database backup patterns (similar to application backup)
- `bootstrap/helpers/databases.php` - Retention policy patterns

### External Links
- [Laravel Queues](https://laravel.com/docs/queues)
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [AWS S3 PHP SDK](https://docs.aws.amazon.com/sdk-for-php/)
- [Cron Expressions](https://crontab.guru/)

---

**Last Updated:** 2025-02-07
**Version:** 1.0.0
**Status:** ✅ Production Ready
