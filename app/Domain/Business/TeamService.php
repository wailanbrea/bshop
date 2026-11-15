<?php

namespace App\Domain\Business;

use App\Models\BusinessProfile;
use App\Models\Role;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeamService
{
    public function __construct(private StoreAuthorizationService $stores, private AuditTrailService $audit) {}

    private function lock(User $actor): User
    {
        BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
        $current = User::with('roles')->findOrFail($actor->id);
        abort_unless($current->permits('members.manage'), 403, 'Tu permiso para gestionar usuarios ya no está vigente.');

        return $current;
    }

    private function validateAccess(array $data, User $actor): Role
    {
        $role = Role::find($data['role_id']);
        if (! $role) {
            throw ValidationException::withMessages(['role_id' => 'Selecciona un rol válido.']);
        }
        if (count(array_intersect($data['store_ids'], $this->stores->accessible($actor)->pluck('id')->all())) !== count($data['store_ids'])) {
            throw ValidationException::withMessages(['store_ids' => 'Sólo puedes asignar sucursales a las que tienes acceso.']);
        }

        return $role;
    }

    public function invite(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $actor = $this->lock($actor);
            $this->validateAccess($data, $actor);
            if (User::where('email', $data['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'Esta persona ya tiene una cuenta en este negocio. Gestiona su acceso desde el equipo.']);
            }
            if (TeamInvitation::where('email', $data['email'])->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['email' => 'Ya existe una invitación vigente para este correo.']);
            }
            $token = Str::random(64);
            $invite = TeamInvitation::create(['email' => $data['email'], 'role_id' => $data['role_id'], 'invited_by_user_id' => $actor->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(72)]);
            foreach ($data['store_ids'] as $id) {
                DB::table('invitation_store_access')->insert(['invitation_id' => $invite->id, 'store_id' => $id]);
            }
            $this->audit->record('user.invited', $invite, after: ['email' => $invite->email, 'role_id' => $invite->role_id, 'store_ids' => $data['store_ids']], actor: $actor);

            return ['invitation' => $invite->refresh(), 'invitation_url' => url('/join/'.$token)];
        });
    }

    public function update(int $id, array $data, User $actor): User
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $actor = $this->lock($actor);
            $role = $this->validateAccess($data, $actor);
            $user = User::with('roles')->findOrFail($id);
            $oldStores = DB::table('store_user')->where('user_id', $user->id)->pluck('store_id')->all();
            abort_if(array_diff($oldStores, $this->stores->accessible($actor)->pluck('id')->all()) !== [], 403, 'El usuario tiene accesos fuera de tus sucursales autorizadas.');
            if ($user->is_active && $user->roles->contains('code', 'owner') && ($role->code !== 'owner' || $data['status'] !== 'active')) {
                $owners = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->count();
                if ($owners <= 1) {
                    throw ValidationException::withMessages(['role_id' => 'El negocio debe conservar al menos un propietario activo.']);
                }
            }
            $before = ['is_active' => $user->is_active, 'role_ids' => $user->roles->pluck('id')->all(), 'store_ids' => $oldStores];
            $user->forceFill(['is_active' => $data['status'] === 'active'])->save();
            $user->roles()->sync([$role->id]);
            DB::table('store_user')->where('user_id', $user->id)->delete();
            foreach ($data['store_ids'] as $store) {
                DB::table('store_user')->insert(['user_id' => $user->id, 'store_id' => $store]);
            }
            $this->audit->record('user.access_updated', $user, $before, ['is_active' => $user->is_active, 'role_ids' => [$role->id], 'store_ids' => $data['store_ids']], $actor);

            return $user;
        });
    }

    public function revoke(int $id, User $actor): TeamInvitation
    {
        return DB::transaction(function () use ($id, $actor) {
            $actor = $this->lock($actor);
            $invite = TeamInvitation::findOrFail($id);
            abort_if($invite->status === 'accepted', 409, 'La invitación ya fue aceptada. Gestiona el usuario.');
            if ($invite->status === 'pending') {
                $before = $invite->attributesToArray();
                $invite->update(['status' => 'revoked']);
                $this->audit->record('user.invitation_revoked', $invite, $before, actor: $actor);
            }

            return $invite;
        });
    }
}
