<?php

namespace App\Services\Auth;

use App\Models\LoginNotification;
use App\Models\User;
use App\Notifications\NewDeviceLogin;
use App\Notifications\NewLocationLogin;

class LoginNotificationService
{
    /**
     * Process login notification and send alerts if needed.
     */
    public function processLoginNotification(LoginNotification $notification): void
    {
        if (!$notification->is_new_device && !$notification->is_new_location) {
            return;
        }

        $user = $notification->user;

        if ($notification->is_new_device) {
            $this->notifyNewDevice($user, $notification);
        } elseif ($notification->is_new_location) {
            $this->notifyNewLocation($user, $notification);
        }

        $notification->markAsNotified();
    }

    /**
     * Send notification for new device login.
     */
    protected function notifyNewDevice(User $user, LoginNotification $notification): void
    {
        $user->notify(new NewDeviceLogin($notification));
    }

    /**
     * Send notification for new location login.
     */
    protected function notifyNewLocation(User $user, LoginNotification $notification): void
    {
        $user->notify(new NewLocationLogin($notification));
    }

    /**
     * Get pending notifications that need to be sent.
     */
    public function getPendingNotifications(int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return LoginNotification::requiresNotification()
            ->with('user')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Process all pending notifications.
     */
    public function processPendingNotifications(): int
    {
        $notifications = $this->getPendingNotifications();
        $count = 0;

        foreach ($notifications as $notification) {
            $this->processLoginNotification($notification);
            $count++;
        }

        return $count;
    }

    /**
     * Clean up old notifications.
     */
    public function cleanupOld(int $days = 90): int
    {
        return LoginNotification::where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
