# Application Backup & Restore Implementation Summary

## Overview
Complete application snapshot functionality for Coolify has been implemented, including configuration, environment variables, file volumes, and deployment settings with S3-compatible storage.

## Completed Phases

### ✅ Phase 1: Database & Models
**Files Created:**
- `database/migrations/2025_02_07_120000_create_scheduled_application_backups_table.php`
  - Polymorphic relationship to applications
  - S3 storage reference
  - Retention policy fields (amount, days, max storage)
  - Enabled/disabled status
  - Cron frequency support

- `database/migrations/2025_02_07_120001_create_scheduled_application_backup_executions_table.php`
  - Status tracking (success, failed, running)
  - Size, filename, checksum fields
  - S3 upload tracking
  - JSON metadata storage
  - Finished at timestamp

- `app/Models/ScheduledApplicationBackup.php`
  - MorphTo relationship to Application
  - Relationships to Team, S3Storage, executions
  - `server()` method (reuses pattern from ScheduledDatabaseBackup)
  - `ownedByCurrentTeam()` scope
  - Pagination support

- `app/Models/ScheduledApplicationBackupExecution.php`
  - BelongsTo ScheduledApplicationBackup
  - Casts for boolean and JSON fields

**Files Modified:**
- `app/Models/Application.php`
  - Added `scheduledBackups()` morphMany relationship

### ✅ Phase 2: Backup Job Core Logic
**Files Created:**
- `app/Jobs/ApplicationBackupJob.php`
  - `collectApplicationConfiguration()` - Uses ConfigurationGenerator service to export complete config
  - `archiveFileVolumes()` - Creates tarball of LocalFileVolume files
  - `createBackupTarball()` - Combines config.json + volumes.tar.gz
  - `calculateChecksum()` - SHA256 for integrity
  - `calculateBackupSize()` - Returns size in bytes
  - Cleanup methods for temporary files

**Key Features:**
- Reuses ConfigurationGenerator service for complete application export
- Handles missing file volumes gracefully
- Validates all paths to prevent command injection
- Proper error handling and logging

### ✅ Phase 3: S3 Upload & Retention
**Functions Created:**
- In `bootstrap/helpers/applications.php`:
  - `removeOldBackupsFromS3ForApplications()` - Main retention function
  - `deleteOldApplicationBackupsFromS3()` - Applies three retention policies:
    1. By count: Keep N most recent backups
    2. By age: Delete backups older than N days
    3. By storage: Delete oldest backups until total size < N GB

**Key Features:**
- Reuses helper container pattern from DatabaseBackupJob
- Uses MinIO client (mc) in Docker container
- Sets up S3 alias and copies files
- Cleanup helper container after upload
- Proper credential escaping

### ✅ Phase 4: Scheduling Integration
**Files Modified:**
- `app/Jobs/ScheduledJobManager.php`
  - Added import for ScheduledApplicationBackup
  - Renamed `processScheduledBackups()` to `processScheduledDatabaseBackups()`
  - Added `processScheduledApplicationBackups()` method
  - Renamed `shouldProcessBackup()` to `shouldProcessDatabaseBackup()`
  - Added `shouldProcessApplicationBackup()` method
  - Updated handle() to call both database and application backup processors

**Key Features:**
- Validates application exists
- Gets server and checks if functional
- Evaluates cron expression in server timezone
- Dispatches ApplicationBackupJob if due
- Cleanup for orphaned backups (application deleted)
- Proper error handling with logging

### ✅ Phase 5: Restore Functionality
**Files Created:**
- `app/Jobs/ApplicationRestoreJob.php`
  - `downloadFromS3()` - Downloads backup from S3 using helper container
  - `extractAndValidate()` - Extracts tarball and validates checksum
  - `checkConflicts()` - Compares current config with backup
  - `restoreConfiguration()` - Restores application settings
  - `restoreEnvironmentVariables()` - Restores env vars
  - `restoreFileVolumes()` - Restores file volumes
  - `cleanupRestoreFiles()` - Removes temporary files

**Key Features:**
- Database transaction for atomic restore
- Checksum verification before restore
- Conflict detection and warnings
- Handles relative and absolute paths
- Recreates LocalFileVolume records if needed
- Rollback on failure

### ✅ Phase 8: Notifications
**Files Created:**
- `app/Notifications/Application/BackupSuccess.php`
- `app/Notifications/Application/BackupFailed.php`
- `app/Notifications/Application/BackupSuccessWithS3Warning.php`

**Channels Supported:**
- Email
- Discord
- Telegram
- Pushover
- Slack
- Webhook

## Remaining Work

### ⏳ Phase 6: UI Components - Backup Management
**To Create:**
1. Modify `app/Livewire/Project/Service/Storage.php`
   - Add "Backups" tab to existing tabs
   - Load application backups
   - Add backup counting logic

2. Modify `resources/views/livewire/project/service/storage.blade.php`
   - Add "Backups" tab button
   - Add backup content section with Alpine.js

3. Create `app/Livewire/Project/Application/BackupSchedule.php`
   - Inline form for creating scheduled backups
   - Fields: frequency, S3 storage, retention policies
   - Authorization using `@can('update', $resource)`

4. Create `app/Livewire/Project/Application/BackupExecutionsList.php`
   - Paginated list of backup executions
   - Status, date, size, checksum display
   - Action buttons: Download, Restore
   - Filter by status
   - Sort by date

5. Create `app/Livewire/Project/Application/BackupRestore.php`
   - Confirmation dialog for restore
   - Shows backup metadata
   - Conflict warnings
   - Progress tracking

6. Add routes for:
   - POST `/application/{uuid}/backup/schedule` - create schedule
   - POST `/application/{uuid}/backup/now` - manual backup
   - POST `/application/{uuid}/backup/{execution_id}/restore` - restore

**To Modify:**
- `app/Policies/ApplicationPolicy.php`
  - Add `manageBackups()` authorization method

### ⏳ Phase 7: UI Components - Restore
**Features:**
- Restore workflow with confirmation
- Progress tracking via Livewire polling
- Real-time status updates
- Error handling with rollback
- Conflict detection display

### ⏳ Phase 9: Testing & Polish
**Unit Tests Needed:**
- Model relationships
- Configuration collection
- Retention policy logic
- Checksum calculation

**Feature Tests Needed:**
- Create scheduled backup
- Trigger manual backup
- Restore from backup
- Authorization checks
- S3 upload/download
- File volume handling

**Edge Cases to Test:**
- Application changed since backup
- Missing file volumes
- S3 connection failure
- Large file volumes
- Concurrent backups
- Configuration conflicts

## Testing Checklist

### Backend Testing
- [ ] Migrations run successfully
- [ ] Models can be created and queried
- [ ] ScheduledApplicationBackup has correct relationships
- [ ] ApplicationBackupJob creates tarball with correct structure
- [ ] ConfigurationGenerator exports all application settings
- [ ] File volumes are correctly archived
- [ ] S3 upload works with helper container
- [ ] Checksum validation works
- [ ] Retention policies delete old backups
- [ ] ScheduledJobManager processes application backups
- [ ] ApplicationRestoreJob restores configuration
- [ ] ApplicationRestoreJob restores file volumes
- [ ] Database transaction rollback works on restore failure
- [ ] Notifications are sent correctly

### Integration Testing
- [ ] Create scheduled backup via UI
- [ ] Scheduled backup runs at correct time
- [ ] Manual backup runs immediately
- [ ] Backup appears in executions list
- [ ] Can download backup from S3
- [ ] Can restore from backup
- [ ] Restore shows conflict warnings
- [ ] Authorization checks work
- [ ] Large file volumes complete successfully
- [ ] Missing file volumes handled gracefully

## Database Schema

### scheduled_application_backups
```sql
- id (bigint, primary key)
- uuid (string, unique)
- description (text, nullable)
- enabled (boolean, default true)
- save_s3 (boolean, default true)
- frequency (string) - Cron expression
- application_id (bigint, polymorphic)
- application_type (string, polymorphic)
- s3_storage_id (bigint, nullable)
- team_id (bigint, foreign key)
- application_backup_retention_amount_s3 (integer, default 0)
- application_backup_retention_days_s3 (integer, default 0)
- application_backup_retention_max_storage_s3 (decimal, default 0)
- created_at (timestamp)
- updated_at (timestamp)
```

### scheduled_application_backup_executions
```sql
- id (bigint, primary key)
- uuid (string, unique)
- status (enum: success, failed, running)
- message (longtext, nullable)
- size (text, nullable)
- filename (text, nullable)
- checksum (text, nullable)
- metadata (json, nullable)
- s3_uploaded (boolean, default false)
- s3_storage_deleted (boolean, default false)
- scheduled_application_backup_id (bigint, foreign key)
- finished_at (timestamp, nullable)
- created_at (timestamp)
- updated_at (timestamp)
```

## File Structure

### New Files
```
database/migrations/
  2025_02_07_120000_create_scheduled_application_backups_table.php
  2025_02_07_120001_create_scheduled_application_backup_executions_table.php

app/Models/
  ScheduledApplicationBackup.php
  ScheduledApplicationBackupExecution.php

app/Jobs/
  ApplicationBackupJob.php
  ApplicationRestoreJob.php

app/Notifications/Application/
  BackupSuccess.php
  BackupFailed.php
  BackupSuccessWithS3Warning.php
```

### Modified Files
```
app/Models/Application.php
app/Jobs/ScheduledJobManager.php
bootstrap/helpers/applications.php
```

## Key Implementation Details

### Configuration Export
Uses `ConfigurationGenerator` service which exports:
- Build settings (dockerfile, compose, commands)
- Source (git repo, branch, commit)
- Domains (fqdn, ports, redirects)
- Environment variables (production + preview)
- Settings
- Limits (CPU, memory)
- Health checks
- Webhook secrets
- Swarm settings

Plus additional metadata:
- File volumes list
- Backup metadata (date, counts)

### S3 Upload Pattern
Reuses helper container pattern:
1. Start helper container with backup volume mounted
2. Set S3 alias with credentials
3. Copy file to S3
4. Cleanup helper container

### Retention Policy
Three policies (any or all can apply):
1. **By count**: Keep N most recent backups
2. **By age**: Delete backups older than N days
3. **By storage**: Delete oldest backups until total size < N GB

### Restore Process
1. Download backup from S3
2. Extract and validate checksum
3. Check for configuration conflicts
4. Start database transaction
5. Restore configuration
6. Restore environment variables
7. Restore file volumes
8. Commit transaction
9. Cleanup temporary files

Rollback on any failure.

## Security Considerations

✅ **Implemented:**
- S3 credentials encrypted in database
- Environment variables remain encrypted
- All paths validated with `validateShellSafePath()`
- All paths escaped with `escapeshellarg()`
- SHA256 checksum verification
- Database transactions for atomic restore

⏳ **To Implement:**
- `@can('manageBackups', $application)` in UI components
- Policy check in ApplicationPolicy

## Performance Considerations

✅ **Implemented:**
- Queued jobs for background processing
- Streaming tar creation (not loading into memory)
- Helper containers cleaned up after use
- Proper error handling prevents resource leaks

⏳ **To Test:**
- Large application backups
- Many file volumes
- Concurrent backups

## Next Steps

1. **Run migrations:**
   ```bash
   php artisan migrate
   ```

2. **Create a test backup:**
   - Use PHP Artisan tinker to create ScheduledApplicationBackup
   - Test ApplicationBackupJob manually
   - Verify S3 upload

3. **Test restore:**
   - Test ApplicationRestoreJob manually
   - Verify configuration restored
   - Verify file volumes restored

4. **Build UI components** (Phase 6 & 7)

5. **Write tests** (Phase 9)

6. **Integration testing**

## Notes

- **No Local Storage**: All backups stored in S3 only (save_s3 always true)
- **File Volumes Only**: LocalPersistentVolume (Docker volumes) NOT backed up, only LocalFileVolume
- **Complete Snapshots**: Includes config, env vars, file volumes, deployment settings
- **Integrity Check**: SHA256 checksum verified before restore
- **Conflict Detection**: Warns if application changed since backup
- **Rollback Support**: Failed restores don't affect application state
- **Streaming**: Large backups use tar streaming to avoid memory issues

## Troubleshooting

### Backup Fails
- Check server is functional
- Check S3 credentials
- Check network connectivity
- Check file volume paths exist

### Restore Fails
- Verify checksum matches
- Check for configuration conflicts
- Ensure sufficient disk space
- Check file volume paths are valid

### Scheduled Backup Not Running
- Check ScheduledJobManager is running
- Verify backup is enabled
- Check cron expression is valid
- Check server timezone setting
- Check logs in `scheduled-errors` channel
