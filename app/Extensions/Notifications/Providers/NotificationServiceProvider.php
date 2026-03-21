<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Notifications\Providers;

use FireflyIII\Extensions\Notifications\Services\WebPushService;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WebPushService::class, static function (): WebPushService {
            return new WebPushService(
                subject:    (string) config('firefly.push_notifications.vapid_subject', 'mailto:admin@example.com'),
                publicKey:  (string) config('firefly.push_notifications.vapid_public_key', ''),
                privateKey: (string) config('firefly.push_notifications.vapid_private_key', ''),
            );
        });
    }

    public function boot(): void
    {
        // No boot-time side effects needed
    }
}
