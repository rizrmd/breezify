<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class BackupFailed extends CustomEmailNotification
{
    public string $name;

    public function __construct(public Application $application, public string $error_message)
    {
        $this->onQueue('high');
        $this->name = $application->name;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_failed');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Application backup FAILED for {$this->application->name}");
        $mail->view('emails.application-backup-failed', [
            'name' => $this->name,
            'error_message' => $this->error_message,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':x: Application backup FAILED',
            description: "Application backup for {$this->name} failed.",
            color: DiscordMessage::errorColor(),
        );

        $message->addField('Error', str($this->error_message)->limit(1000), true);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Application backup for {$this->name} FAILED. Error: {$this->error_message}";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Application backup FAILED',
            level: 'error',
            message: "Application backup for {$this->name} failed.<br/><br/><b>Error:</b> {$this->error_message}",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Application backup FAILED';
        $description = "Application backup for {$this->name} failed.";
        $description .= "\n\n*Error:* {$this->error_message}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        $url = $this->application->link();

        return [
            'success' => false,
            'message' => 'Application backup failed',
            'event' => 'backup_failed',
            'application_name' => $this->name,
            'application_uuid' => $this->application->uuid,
            'error_message' => $this->error_message,
            'url' => $url,
        ];
    }
}
