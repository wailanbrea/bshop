<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreAuthorizationService;
use App\Domain\Business\TeamService;
use App\Http\Controllers\Controller;
use App\Http\Requests\TeamAccessRequest;
use App\Models\Role;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessTeamController extends Controller
{
    public function index(Request $request, StoreAuthorizationService $access)
    {
        $stores = $access->accessible($request->user())->orderBy('name')->get();
        $all = DB::table('store_user')->get()->groupBy('user_id');

        return response()->json(['data' => [
            'members' => User::with('roles')->orderBy('id')->get()->map(function ($user) use ($stores, $all) {
                $ids = ($all[$user->id] ?? collect())->pluck('store_id')->all();

                return ['id' => $user->id, 'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->is_active ? 'active' : 'suspended', 'user_active' => $user->is_active, 'role_ids' => $user->roles->pluck('id'), 'role_labels' => $user->roles->pluck('label'), 'store_ids' => array_values(array_intersect($ids, $stores->pluck('id')->all())), 'can_manage' => array_diff($ids, $stores->pluck('id')->all()) === []];
            }),
            'roles' => Role::all(['id', 'code', 'label']), 'stores' => $stores,
            'invitations' => TeamInvitation::where('status', 'pending')->where('expires_at', '>', now())->orderByDesc('id')->get(['id', 'email', 'role_id', 'expires_at', 'status']),
        ]]);
    }

    public function invite(TeamAccessRequest $request, TeamService $service)
    {
        return response()->json(['data' => $service->invite($request->validated(), $request->user())], 201)->header('Cache-Control', 'no-store');
    }

    public function update(TeamAccessRequest $request, TeamService $service)
    {
        $user = $service->update((int) $request->route('user'), $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $user->id, 'status' => $user->is_active ? 'active' : 'suspended']]);
    }

    public function revoke(Request $request, TeamService $service)
    {
        $invite = $service->revoke((int) $request->route('invitation'), $request->user());

        return response()->json(['data' => ['id' => $invite->id, 'status' => $invite->status]]);
    }
}
