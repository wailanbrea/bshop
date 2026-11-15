<?php

namespace App\Domain\Business;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class StoreAuthorizationService
{
    public function accessible(User $user): Builder
    {
        return Store::whereIn('id', DB::table('store_user')->select('store_id')->where('user_id', $user->id));
    }

    public function authorize(User $user, int|string $id): Store
    {
        return $this->accessible($user)->findOrFail($id);
    }
}
