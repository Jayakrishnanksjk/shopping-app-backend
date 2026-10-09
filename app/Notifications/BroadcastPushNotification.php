<?php

namespace App\Notifications;

use App\Models\PushBroadcast;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BroadcastPushNotification extends Notification
{
    use Queueable;

    public function __construct(protected PushBroadcast $broadcast)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->broadcast->title,
            'message' => $this->broadcast->body,
            'image' => $this->broadcast->resolved_image_url,
            'type' => 'broadcast',
            'broadcast_id' => $this->broadcast->id,
            'redirect_type' => $this->broadcast->redirect_type,
            'redirect_target' => $this->broadcast->redirect_target,
        ];
    }
}
