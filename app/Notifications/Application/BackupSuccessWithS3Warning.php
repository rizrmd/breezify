<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class BackupSuccessWithS3Warning extends CustomEmailNotification
{
    public string $name;

    public function __construct(public Application $application, public int $size)
    {
        $this->onQueue('high');
        $this->name = $application->name;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_success');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Application backup successful (with S3 warning) for {$this->application->name}");
        $mail->view('emails.application-backup-success-with-s3-warning', [
            'name' => $this->name,
            'size' => $this->formatSize($this->size),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':warning: Application backup successful (S3 upload failed)',
            description: "Application backup for {$this->name} was created locally but S3 upload failed.",
            color: DiscordMessage::warningColor(),
        );

        $message->addField('Size', $this->formatSize($this->size), true);
        $message->addField('Warning', 'S3 upload failed. Check your S3 configuration.', true);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Application backup for {$this->name} was created locally but S3 upload failed. Size: {$this->formatSize($this->size)}.";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Application backup successful (with S3 warning)',
            level: 'warning',
            message: "Application backup for {$this->name} was created locally but S3 upload failed.<br/><br/><b>Size:</b> {$this->formatSize($this->size)}.<br/><b>Warning:</b> Check your S3 configuration.",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Application backup successful (with S3 warning)';
        $description = "Application backup for {$this->name} was created locally but S3 upload failed.";
        $description .= "\n\n*Size:* {$this->formatSize($this->size)}";
        $description .= "\n*Warning:* Check your S3 configuration.";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::warningColor()
        );
    }

    public function toWebhook(): array
    {
        $url = $this->application->link();

        return [
            'success' => true,
            'warning' => true,
            'message' => 'Application backup successful (S3 upload failed)',
            'event' => 'backup_success_with_s3_warning',
            'application_name' => $this->name,
            'application_uuid' => $this->application->uuid,
            'size' => $this->size,
            'size_formatted' => $this->formatSize($this->size),
            'url' => $url,
        ];
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }
}
