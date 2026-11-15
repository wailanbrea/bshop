<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\StoreAuthorizationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRequest;
use App\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function session(Request $request)
    {
        return response()->json(['data' => [
            'user' => $request->user()->only('id', 'name', 'email'),
            'business' => BusinessProfile::current(),
            'roles' => $request->user()->roles->pluck('label'),
            'permissions' => $request->user()->roles->flatMap->permissions->unique()->values(),
        ]]);
    }

    public function show(Request $request, StoreAuthorizationService $stores)
    {
        Gate::authorize('stores.view');

        return response()->json(['data' => [
            'business' => BusinessProfile::current(),
            'stores' => $stores->accessible($request->user())->orderBy('name')->get(),
            'permissions' => $request->user()->roles->flatMap->permissions->unique()->values(),
        ]]);
    }

    public function update(BusinessRequest $request, AuditTrailService $audit)
    {
        Gate::authorize('business.manage');
        DB::transaction(function () use ($request, $audit) {
            $business = BusinessProfile::current();
            $before = $business->attributesToArray();
            $business->update($request->validated());
            $audit->record('business.updated', $business, $before);
        });

        return response()->json(['data' => BusinessProfile::current()]);
    }
}
