<?php

namespace App\Jobs\Middleware;

use App\Domain\Business\StoreAuthorizationService;
use App\Domain\Business\StoreContext;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

final class WithStoreContext
{
    public function __construct(public readonly int $userId, public readonly int $storeId, public readonly string $permission) {}

    public function handle(object $job, Closure $next): mixed
    {
        $context = app(StoreContext::class);
        $context->clear();
        try {
            $user = User::with('roles')->findOrFail($this->userId);
            if (! $user->permits($this->permission)) {
                throw new AuthorizationException('Job access has been revoked.');
            }
            $store = app(StoreAuthorizationService::class)->authorize($user, $this->storeId);
            if (! $store->is_active) {
                throw new AuthorizationException('Store is inactive.');
            }
            $context->useStore($store);

            return $next($job);
        } finally {
            $context->clear();
        }
    }
}
