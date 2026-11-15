<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationSettingsController extends Controller
{
    public function show()
    {
        $hours = DB::table('business_settings')->where('id', 1)->value('reserve_expiry_hours');

        return response()->json(['data' => ['hours' => $hours === null ? null : (int) $hours]]);
    }

    public function update(Request $request, AuditTrailService $audit)
    {
        $data = $request->validate(['hours' => ['present', 'nullable', 'integer', 'min:1', 'max:8760'], 'previous_hours' => ['present', 'nullable', 'integer', 'min:1', 'max:8760']]);
        DB::transaction(function () use ($data, $request, $audit) {
            abort_unless(User::whereKey($request->user()->id)->sharedLock()->firstOrFail()->permits('business.manage'), 403);
            $settings = DB::table('business_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $before = $settings->reserve_expiry_hours === null ? null : (int) $settings->reserve_expiry_hours;
            abort_if($before !== ($data['previous_hours'] === null ? null : (int) $data['previous_hours']), 409, 'El plazo fue modificado. Actualiza antes de guardar.');
            $hours = $data['hours'] === null ? null : (int) $data['hours'];
            if ($before !== $hours) {
                DB::table('business_settings')->where('id', 1)->update(['reserve_expiry_hours' => $hours, 'updated_at' => now()]);
                $audit->record('reservations.policy.updated', BusinessProfile::current(), before: ['hours' => $before], after: ['hours' => $hours], actor: $request->user());
            }
        });

        return $this->show();
    }
}
