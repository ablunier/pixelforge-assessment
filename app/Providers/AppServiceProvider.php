<?php

namespace App\Providers;

use App\Models\User;
use App\Pixelforge\CachingPixelforgeClient;
use App\Pixelforge\HttpPixelforgeClient;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /** How many Generations a user may start per minute. */
    public const int GENERATIONS_PER_MINUTE = 10;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn (Application $app): PixelforgeClient => new CachingPixelforgeClient(
            new HttpPixelforgeClient(
                $app->make(HttpFactory::class),
                (string) config('services.pixelforge.base_url'),
                config('services.pixelforge.api_key'),
            ),
            $app->make(Cache::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureGates();
    }

    /**
     * Only admins see the admin report: the business's Charges, Provider Costs and Profit.
     */
    protected function configureGates(): void
    {
        Gate::define('viewAdminReport', fn (User $user): bool => $user->is_admin);
    }

    /**
     * Generate and Try again each start a Generation that Pixelforge bills
     * (ADR-0006). Live validation of the form starts nothing, so it's free.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('generations', fn (Request $request): Limit => $request->isPrecognitive()
            ? Limit::none()
            : Limit::perMinute(self::GENERATIONS_PER_MINUTE)
                ->by((string) $request->user()?->getAuthIdentifier())
                ->response(fn (): RedirectResponse => back()->withErrors([
                    'generate' => 'Too many Generations at once. Wait a minute, then try again.',
                ])));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
