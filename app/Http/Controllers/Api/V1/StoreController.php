<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\StoreContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequest;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StoreController extends Controller
{
    public function create(StoreRequest $request, AuditTrailService $audit)
    {
        Gate::authorize('stores.manage');
        $store = DB::transaction(function () use ($request, $audit) {
            $store = Store::create($request->validated());
            DB::table('store_user')->insert(['user_id' => $request->user()->id, 'store_id' => $store->id]);
            $audit->record('store.created', $store);

            return $store;
        });

        return response()->json(['data' => $store], 201);
    }

    public function update(StoreRequest $request, StoreContext $context, AuditTrailService $audit)
    {
        Gate::authorize('stores.manage');
        DB::transaction(function () use ($request, $context, $audit) {
            $store = $context->store();
            $before = $store->attributesToArray();
            $store->update($request->validated());
            $audit->record('store.updated', $store, $before);
        });

        return response()->json(['data' => $context->store()]);
    }
}
