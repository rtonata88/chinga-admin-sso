<?php

namespace App\Services\Auth;

use App\Models\LoginNotification;
use App\Models\User;
use Illuminate\Http\Request;

class DeviceDetectionService
{
    /**
     * Analyze login and detect new device/location.
     */
    public function analyzeLogin(User $user, Request $request): LoginNotification
    {
        $fingerprint = $this->generateFingerprint($request);
        $deviceInfo = $this->parseUserAgent($request->userAgent());
        $locationInfo = $this->getLocationFromIp($request->ip());

        $isNewDevice = !LoginNotification::hasDeviceFingerprint($user->id, $fingerprint);
        $isNewLocation = !LoginNotification::hasLocation(
            $user->id,
            $locationInfo['country_code'],
            $locationInfo['city']
        );

        return LoginNotification::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_fingerprint' => $fingerprint,
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'platform' => $deviceInfo['platform'],
            'country_code' => $locationInfo['country_code'],
            'city' => $locationInfo['city'],
            'is_new_device' => $isNewDevice,
            'is_new_location' => $isNewLocation,
        ]);
    }

    /**
     * Check if this is a new device for the user.
     */
    public function isNewDevice(User $user, Request $request): bool
    {
        $fingerprint = $this->generateFingerprint($request);
        return !LoginNotification::hasDeviceFingerprint($user->id, $fingerprint);
    }

    /**
     * Check if this is a new location for the user.
     */
    public function isNewLocation(User $user, Request $request): bool
    {
        $location = $this->getLocationFromIp($request->ip());
        return !LoginNotification::hasLocation(
            $user->id,
            $location['country_code'],
            $location['city']
        );
    }

    /**
     * Generate a device fingerprint from request data.
     */
    public function generateFingerprint(Request $request): string
    {
        $components = [
            $request->userAgent(),
            $request->header('Accept-Language'),
            $request->header('Accept-Encoding'),
        ];

        return hash('sha256', implode('|', array_filter($components)));
    }

    /**
     * Parse user agent to extract device info.
     */
    public function parseUserAgent(?string $userAgent): array
    {
        $deviceType = 'unknown';
        $browser = 'unknown';
        $platform = 'unknown';

        if (!$userAgent) {
            return compact('device_type', 'browser', 'platform');
        }

        // Detect device type
        if (preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent)) {
            $deviceType = preg_match('/iPad|Tablet/i', $userAgent) ? 'tablet' : 'mobile';
        } else {
            $deviceType = 'desktop';
        }

        // Detect browser
        if (preg_match('/Chrome\/[\d.]+/i', $userAgent) && !preg_match('/Edge|OPR/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\/[\d.]+/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\/[\d.]+/i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/Edge\/[\d.]+|Edg\/[\d.]+/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR\/[\d.]+|Opera\/[\d.]+/i', $userAgent)) {
            $browser = 'Opera';
        }

        // Detect platform
        if (preg_match('/Windows/i', $userAgent)) {
            $platform = 'Windows';
        } elseif (preg_match('/Macintosh|Mac OS X/i', $userAgent)) {
            $platform = 'macOS';
        } elseif (preg_match('/Linux/i', $userAgent) && !preg_match('/Android/i', $userAgent)) {
            $platform = 'Linux';
        } elseif (preg_match('/Android/i', $userAgent)) {
            $platform = 'Android';
        } elseif (preg_match('/iPhone|iPad|iOS/i', $userAgent)) {
            $platform = 'iOS';
        }

        return [
            'device_type' => $deviceType,
            'browser' => $browser,
            'platform' => $platform,
        ];
    }

    /**
     * Get location information from IP address.
     */
    public function getLocationFromIp(?string $ip): array
    {
        // Default values
        $result = [
            'country_code' => null,
            'city' => null,
        ];

        if (!$ip || $ip === '127.0.0.1' || $ip === '::1') {
            return $result;
        }

        // TODO: Integrate with IP geolocation service
        // Options: MaxMind GeoIP, ipapi.com, ip-api.com, etc.
        // Example with ip-api.com (free tier):
        //
        // try {
        //     $response = Http::timeout(5)->get("http://ip-api.com/json/{$ip}");
        //     if ($response->successful()) {
        //         $data = $response->json();
        //         $result['country_code'] = $data['countryCode'] ?? null;
        //         $result['city'] = $data['city'] ?? null;
        //     }
        // } catch (\Exception $e) {
        //     logger()->warning("IP geolocation failed: " . $e->getMessage());
        // }

        return $result;
    }

    /**
     * Get known devices for a user.
     */
    public function getKnownDevices(User $user, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return LoginNotification::forUser($user->id)
            ->select(['device_fingerprint', 'device_type', 'browser', 'platform', 'ip_address', 'created_at'])
            ->groupBy('device_fingerprint')
            ->latest()
            ->limit($limit)
            ->get();
    }
}
