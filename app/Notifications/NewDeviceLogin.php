<?php

namespace App\Notifications;

use App\Models\LoginNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceLogin extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LoginNotification $loginNotification
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $device = $this->loginNotification->device_type ?? 'Unknown device';
        $browser = $this->loginNotification->browser ?? 'Unknown browser';
        $platform = $this->loginNotification->platform ?? 'Unknown platform';
        $location = $this->formatLocation();
        $time = $this->loginNotification->created_at->format('F j, Y \a\t g:i A');

        return (new MailMessage)
            ->subject('New Device Login to Your Account')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('We noticed a new login to your account from a device we don\'t recognize.')
            ->line("**Device:** {$device} ({$browser} on {$platform})")
            ->line("**Location:** {$location}")
            ->line("**Time:** {$time}")
            ->line("**IP Address:** {$this->loginNotification->ip_address}")
            ->line('If this was you, you can ignore this email.')
            ->action('Review Account Security', url('/settings/security/log'))
            ->line('If you don\'t recognize this activity, please secure your account immediately by changing your password and enabling two-factor authentication.');
    }

    /**
     * Format the location string.
     */
    protected function formatLocation(): string
    {
        $parts = array_filter([
            $this->loginNotification->city,
            $this->loginNotification->country_code,
        ]);

        return empty($parts) ? 'Unknown location' : implode(', ', $parts);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_device_login',
            'login_notification_id' => $this->loginNotification->id,
            'ip_address' => $this->loginNotification->ip_address,
            'device_type' => $this->loginNotification->device_type,
        ];
    }
}
