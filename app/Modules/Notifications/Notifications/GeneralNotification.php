<?php

namespace App\Modules\Notifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $title;
    protected string $message;
    protected array $data;

    /**
     * Δημιουργία νέας ειδοποίησης.
     */
    public function __construct(string $title, string $message, array $data = [])
    {
        $this->title = $title;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * Κανάλια ειδοποίησης.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Δεδομένα για αποθήκευση στη βάση.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
