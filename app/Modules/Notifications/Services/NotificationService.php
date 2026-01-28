<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    /**
     * Αποστολή ειδοποίησης σε χρήστη.
     */
    public function notifyUser(int $userId, string $title, string $message, array $data = []): void
    {
        $user = User::find($userId);
        
        if (!$user) {
            return;
        }

        $user->notify(new \App\Modules\Notifications\Notifications\GeneralNotification(
            $title,
            $message,
            $data
        ));
    }

    /**
     * Αποστολή ειδοποίησης σε όλους τους χρήστες τμήματος.
     */
    public function notifyDepartmentUsers(int $departmentId, string $title, string $message, array $data = []): void
    {
        $users = User::where('department_id', $departmentId)
            ->where('is_active', true)
            ->get();

        Notification::send($users, new \App\Modules\Notifications\Notifications\GeneralNotification(
            $title,
            $message,
            $data
        ));
    }

    /**
     * Λήψη ειδοποιήσεων χρήστη.
     */
    public function getUserNotifications(User $user, int $limit = 20): array
    {
        $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $notifications->map(fn($n) => $this->formatNotification($n))->toArray();
    }

    /**
     * Αριθμός μη αναγνωσμένων ειδοποιήσεων.
     */
    public function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * Σήμανση ειδοποίησης ως αναγνωσμένη.
     */
    public function markAsRead(string $notificationId, User $user): bool
    {
        $notification = $user->notifications()->find($notificationId);
        
        if (!$notification) {
            return false;
        }

        $notification->markAsRead();
        return true;
    }

    /**
     * Σήμανση όλων ως αναγνωσμένες.
     */
    public function markAllAsRead(User $user): void
    {
        $user->unreadNotifications->markAsRead();
    }

    /**
     * Μορφοποίηση ειδοποίησης για API.
     */
    protected function formatNotification(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'τίτλος' => $notification->data['title'] ?? 'Ειδοποίηση',
            'μήνυμα' => $notification->data['message'] ?? '',
            'δεδομένα' => $notification->data['data'] ?? [],
            'αναγνωσμένη' => $notification->read_at !== null,
            'αναγνώστηκε_στις' => $notification->read_at,
            'δημιουργήθηκε' => $notification->created_at,
        ];
    }
}
