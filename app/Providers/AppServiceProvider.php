<?php

namespace App\Providers;

use App\Domain\Business\InstallBusiness;
use App\Domain\Business\StoreContext;
use App\Domain\Operations\SchedulerMonitor;
use App\Http\Controllers\SharedOrderController;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SchedulerMonitor::class);
        $this->app->scoped(StoreContext::class, fn () => new StoreContext);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ScheduledTaskFinished::class, fn ($event) => app(SchedulerMonitor::class)->finished($event));
        Event::listen(ScheduledTaskFailed::class, fn ($event) => app(SchedulerMonitor::class)->failed($event));
        foreach (['business.manage', 'stores.view', 'stores.manage', 'warehouses.view', 'warehouses.manage', 'audit.view', 'members.manage', 'catalog.view', 'catalog.manage', 'catalog.costs', 'inventory.view', 'inventory.manage', ...InstallBusiness::SALES_PERMISSIONS, 'sales.cancel', 'payments.reverse', 'returns.receive', 'returns.adjust', 'refunds.record', 'credits.apply', 'credits.reverse', 'returns.reverse'] as $permission) {
            Gate::define($permission, function (User $user) use ($permission) {
                return $user->permits($permission);
            });
        }
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(hash('sha256', strtolower((string) $request->input('email')).'|'.$request->ip())),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('recovery', fn (Request $request) => Limit::perMinute(3)->by($request->path().'|'.$request->ip()));
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('order-view-links', fn (Request $request) => Limit::perMinute(30)->by($request->ip())->response(fn () => response()->view('shared-order', ['order' => null], 429, SharedOrderController::HEADERS)));
        RateLimiter::for('order-view-manage', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('public-order-requests', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('public-order-confirm', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('payments-reverse', fn (Request $request) => Limit::perMinute(15)->by((string) $request->user()?->id));
        RateLimiter::for('returns-receive', fn (Request $request) => Limit::perMinute(15)->by((string) $request->user()?->id));
        RateLimiter::for('returns-reverse', fn (Request $request) => Limit::perMinute(15)->by((string) $request->user()?->id));
        RateLimiter::for('returns-finance', fn (Request $request) => Limit::perMinute(15)->by((string) $request->user()?->id));
        RateLimiter::for('credits-apply', fn (Request $request) => Limit::perMinute(15)->by((string) $request->user()?->id));
        RateLimiter::for('credits-reverse', fn (Request $request) => Limit::perMinute(15)->by((string) $request->user()?->id));
        RateLimiter::for('sales-control-export', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('financial-documents', fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->id));
        RateLimiter::for('catalog-imports', fn (Request $request) => Limit::perMinute(5)->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
