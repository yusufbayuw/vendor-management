<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class DevicePushNotification extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $type,
        public readonly string $tag,
        public readonly int $ttl = 86400,
        public readonly string $urgency = 'normal',
    ) {}

    /** @return array<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon(url('/pwa/icon/192.png'))
            ->badge(url('/pwa/icon/96.png'))
            ->lang('id-ID')
            ->tag($this->tag)
            ->renotify()
            ->vibrate([150, 80, 150])
            ->data([
                'url' => $this->url,
                'type' => $this->type,
            ])
            ->options([
                'TTL' => $this->ttl,
                'urgency' => $this->urgency,
            ]);
    }
}
