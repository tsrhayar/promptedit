<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignature;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn () => new PixelforgeClient(
            (string) config('services.pixelforge.base_url'),
            config('services.pixelforge.api_key'),
        ));

        $this->app->bind(WebhookSignature::class, fn () => new WebhookSignature(
            config('services.pixelforge.webhook_secret'),
            (int) config('services.pixelforge.webhook_tolerance_seconds', 300),
        ));

        $this->app->bind(PricingService::class, fn () => new PricingService(
            (int) config('services.pixelforge.markup_percent', 25),
        ));
    }

    public function boot(): void
    {
        Gate::define('view-admin-report', fn (User $user) => $user->is_admin);
    }
}
