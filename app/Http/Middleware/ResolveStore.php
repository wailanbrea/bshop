<?php

namespace App\Http\Middleware;

use App\Domain\Business\StoreAuthorizationService;
use App\Domain\Business\StoreContext;
use Closure;
use Illuminate\Http\Request;

final class ResolveStore
{
    public function handle(Request $request, Closure $next): mixed
    {
        $context = app(StoreContext::class);
        $context->clear();
        try {
            $store = app(StoreAuthorizationService::class)->authorize($request->user(), $request->route('store'));
            $context->useStore($store);

            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
