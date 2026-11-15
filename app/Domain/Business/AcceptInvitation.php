<?php

namespace App\Domain\Business;

use App\Models\BusinessProfile;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptInvitation
{
    public function inspect(string $token): array
    {
        $invite = TeamInvitation::where('token_hash', hash('sha256', $token))->firstOrFail();
        $this->available($invite);
        [$local, $domain] = explode('@', $invite->email, 2);

        return ['company_name' => BusinessProfile::current()->name, 'recipient' => mb_substr($local, 0, 1).'***@'.$domain, 'expires_at' => $invite->expires_at];
    }

    public function accept(string $token, array $data, ?User $authenticated): array
    {
        try {
            return DB::transaction(function () use ($token, $data, $authenticated) {
                $profile = BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
                $invite = TeamInvitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
                if (! hash_equals($invite->email, $data['email'])) {
                    throw ValidationException::withMessages(['email' => 'El correo no coincide con la invitación.']);
                }
                if ($authenticated && (! $authenticated->is_active || $authenticated->email !== $invite->email)) {
                    abort(403, 'Cierra la sesión para usar el correo invitado.');
                }
                if ($invite->status === 'accepted' && $authenticated?->id === $invite->accepted_by_user_id) {
                    return ['user' => $authenticated, 'business' => $profile];
                }
                $this->available($invite);
                $inviter = User::with('roles')->find($invite->invited_by_user_id);
                abort_unless($inviter?->permits('members.manage'), 410, 'El permiso que originó la invitación ya no está vigente.');
                $ids = DB::table('invitation_store_access')->where('invitation_id', $invite->id)->pluck('store_id')->all();
                $allowed = app(StoreAuthorizationService::class)->accessible($inviter)->pluck('id')->all();
                abort_if($ids === [] || array_diff($ids, $allowed) !== [], 410, 'Los accesos cambiaron. Solicita otra invitación.');
                if (User::where('email', $invite->email)->exists()) {
                    throw ValidationException::withMessages(['email' => 'Esta cuenta ya existe. El propietario debe gestionar su acceso.']);
                }
                $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
                $user->roles()->attach($invite->role_id);
                foreach ($ids as $store) {
                    DB::table('store_user')->insert(['user_id' => $user->id, 'store_id' => $store]);
                }
                $invite->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_by_user_id' => $user->id]);
                app(AuditTrailService::class)->record('user.invitation_accepted', $user, after: ['role_ids' => [$invite->role_id], 'store_ids' => $ids], actor: $user);

                return ['user' => $user, 'business' => $profile];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'La cuenta ya existe. Solicita al propietario revisar el acceso.']);
        }
    }

    private function available(TeamInvitation $invite): void
    {
        abort_if($invite->status !== 'pending' || $invite->expires_at->isPast(), 410, 'La invitación ha caducado, fue revocada o ya se utilizó.');
    }
}
