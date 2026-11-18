<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WeeklySettingsController extends Controller
{
    public function show()
    {
        $weekday = DB::table('business_settings')->where('id', 1)->firstOrFail(['weekly_close_weekday'])->weekly_close_weekday;

        return response()->json(['data' => ['close_weekday' => (int) $weekday]]);
    }

    public function update(Request $request, AuditTrailService $audit)
    {
        $data = $request->validate(['close_weekday' => ['required', 'integer', 'between:1,7'], 'previous_weekday' => ['required', 'integer', 'between:1,7']]);
        DB::transaction(function () use ($data, $request, $audit) {
            abort_unless(User::whereKey($request->user()->id)->sharedLock()->firstOrFail()->permits('business.manage'), 403);
            $settings = DB::table('business_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $before = (int) $settings->weekly_close_weekday;
            abort_if($before !== (int) $data['previous_weekday'], 409, 'El día de corte cambió. Actualiza antes de guardar.');
            $weekday = (int) $data['close_weekday'];
            if ($before !== $weekday) {
                DB::table('business_settings')->where('id', 1)->update(['weekly_close_weekday' => $weekday, 'updated_at' => now()]);
                $audit->record('weekly.policy.updated', BusinessProfile::current(), before: ['close_weekday' => $before], after: ['close_weekday' => $weekday], actor: $request->user());
            }
        });

        return $this->show();
    }
}
