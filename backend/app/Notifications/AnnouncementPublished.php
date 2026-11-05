<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AnnouncementPublished extends Notification
{
    use Queueable;

    public function __construct(public Announcement $announcement) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->announcement;
        $scope = $a->campus ? $a->campus->name : 'all campuses';
        $subject = ($a->priority === 'urgent' ? '[URGENT] ' : '') . $a->title;

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Assalam-o-Alaikum ' . $notifiable->name)
            ->line($a->priority === 'normal'
                ? 'A new announcement has been published for ' . $scope . '.'
                : 'This notice (' . $a->priority . ') needs your attention.');

        foreach (preg_split('/\R+/', trim(strip_tags($a->body))) ?: [] as $line) {
            if ($line !== '') $mail->line($line);
        }

        $mail->line('Audience: ' . $a->audience . ' · ' . $scope);
        if ($a->expires_at) $mail->line('Valid until ' . $a->expires_at->format('d/m/Y') . '.');

        return $mail->action('Open the portal', rtrim((string) config('cms.frontend_url'), '/') . '/announcements');
    }
}
