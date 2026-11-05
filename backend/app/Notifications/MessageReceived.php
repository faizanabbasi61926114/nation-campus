<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MessageReceived extends Notification
{
    use Queueable;

    public function __construct(public Message $message) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = $this->message;
        $from = optional($m->sender)->name ?? 'A colleague';
        $excerpt = str($m->body)->strip()->limit(400)->value();

        return (new MailMessage)
            ->subject('New message: ' . $m->subject)
            ->greeting('Assalam-o-Alaikum ' . $notifiable->name)
            ->line($from . ' sent you a message.')
            ->line($excerpt)
            ->action('Read your inbox', rtrim((string) config('cms.frontend_url'), '/') . '/messages');
    }
}
