<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class BackupSuccess extends CustomEmailNotification
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
        $mail->subject("Coolify: Application backup successful for {$this->application->name}");
        $mail->view('emails.application-backup-success', [
            'name' => $this->name,
            'size' => $this->formatSize($this->size),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: Application backup successful',
            description: "Application backup for {$this->name} was successful.",
            color: DiscordMessage::successColor(),
        );

        $message->addField('Size', $this->formatSize($this->size), true);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Application backup for {$this->name} was successful. Size: {$this->formatSize($this->size)}.";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Application backup successful',
            level: 'success',
            message: "Application backup for {$this->name} was successful.<br/><br/><b>Size:</b> {$this->formatSize($this->size)}.",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Application backup successful';
        $description = "Application backup for {$this->name} was successful.";
        $description .= "\n\n*Size:* {$this->formatSize($this->size)}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::successColor()
        );
    }

    public function toWebhook(): array
    {
        $url = $this->application->link();

        return [
            'success' => true,
            'message' => 'Application backup successful',
            'event' => 'backup_success',
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
