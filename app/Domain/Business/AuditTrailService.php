<?php

namespace App\Domain\Business;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class AuditTrailService
{
    public function record(string $event, Model $entity, ?array $before = null, ?array $after = null, ?User $actor = null, bool $system = false): void
    {
        AuditLog::create(['user_id' => $system ? null : ($actor ?? Auth::guard('web')->user())?->id, 'event' => $event, 'entity_type' => $entity->getTable(), 'entity_id' => (string) $entity->getKey(), 'old_data' => $before, 'new_data' => $after ?? $entity->attributesToArray(), 'occurred_at' => now()]);
    }
}
