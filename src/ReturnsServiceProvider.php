<?php

namespace Liberu\Ecommerce\Returns;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Returns\Models\Refund;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\Ecommerce\Returns\Policies\RefundPolicy;
use Liberu\Ecommerce\Returns\Policies\ReturnRequestPolicy;
use Liberu\Ecommerce\Returns\Telemetry\DomainEventLogger;

/**
 * Registered by `ModuleManagerServiceProvider` from `module.json`, never by
 * Composer discovery — the package ships no `extra.laravel.providers`, so an
 * install boots nothing until the deployment names the module in
 * `MODULES_ENABLED`.
 *
 * **Nothing here subscribes to another module's event, and nothing here raises a
 * counter that lives in another module's table.** Either would be an import, and
 * this package has none — `BoundaryTest` reads this file and asserts that every
 * commerce namespace it mentions is this one. The host writes those listeners;
 * the README and `docs/adoption.md` carry them verbatim.
 *
 * Two policies are registered rather than one. A refund row is reachable
 * directly, and an unpolicied model is exposed rather than safe.
 */
class ReturnsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/returns.php', 'returns');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Registered here rather than left to Laravel's convention: the
        // convention maps `App\Models\X` to `App\Policies\XPolicy`, and this
        // module's models are in neither namespace. An unregistered policy is not
        // a closed door — the unanswered gate case is permissive.
        Gate::policy(ReturnRequest::class, ReturnRequestPolicy::class);
        Gate::policy(Refund::class, RefundPolicy::class);

        // Subscribed unconditionally, and silent unless the deployment turns
        // telemetry on. Gating the subscription on config instead would make the
        // setting un-changeable at runtime, which is exactly the thing a
        // deployment wants to flip while it is investigating something.
        Event::subscribe(DomainEventLogger::class);

        $this->publishes([
            __DIR__.'/../config/returns.php' => config_path('returns.php'),
        ], 'returns-config');
    }
}
