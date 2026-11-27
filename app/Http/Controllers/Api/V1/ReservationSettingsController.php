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
    public const DEPOSIT_DEFAULTS = [
        'require_deposit_for_new' => true,
        'deposit_mode' => 'fixed',
        'deposit_value' => '30.00',
        'deposit_instructions' => 'Envía tu depósito por Zelle / transferencia y mándanos la captura por aquí mismo para confirmar tu apartado.',
    ];

    public static function depositPolicy(): array
    {
        $raw = DB::table('business_settings')->where('id', 1)->value('configuration');
        $cfg = $raw ? json_decode($raw, true) : [];
        $stored = is_array($cfg['reservation_deposit'] ?? null) ? $cfg['reservation_deposit'] : [];

        return [
            'require_deposit_for_new' => array_key_exists('require_deposit_for_new', $stored) ? (bool) $stored['require_deposit_for_new'] : self::DEPOSIT_DEFAULTS['require_deposit_for_new'],
            'deposit_mode' => in_array($stored['deposit_mode'] ?? '', ['fixed', 'percent'], true) ? $stored['deposit_mode'] : self::DEPOSIT_DEFAULTS['deposit_mode'],
            'deposit_value' => is_numeric($stored['deposit_value'] ?? null) ? number_format((float) $stored['deposit_value'], 2, '.', '') : self::DEPOSIT_DEFAULTS['deposit_value'],
            'deposit_instructions' => trim((string) ($stored['deposit_instructions'] ?? self::DEPOSIT_DEFAULTS['deposit_instructions'])) ?: self::DEPOSIT_DEFAULTS['deposit_instructions'],
        ];
    }

    public function show()
    {
        $hours = DB::table('business_settings')->where('id', 1)->value('reserve_expiry_hours');

        return response()->json(['data' => [
            'hours' => $hours === null ? null : (int) $hours,
            ...self::depositPolicy(),
        ]]);
    }

    public function update(Request $request, AuditTrailService $audit)
    {
        $data = $request->validate([
            'hours' => ['present', 'nullable', 'integer', 'min:1', 'max:8760'],
            'previous_hours' => ['present', 'nullable', 'integer', 'min:1', 'max:8760'],
            'require_deposit_for_new' => ['sometimes', 'boolean'],
            'deposit_mode' => ['sometimes', 'in:fixed,percent'],
            'deposit_value' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'deposit_instructions' => ['sometimes', 'nullable', 'string', 'max:600'],
        ]);
        DB::transaction(function () use ($data, $request, $audit) {
            abort_unless(User::whereKey($request->user()->id)->sharedLock()->firstOrFail()->permits('business.manage'), 403);
            $settings = DB::table('business_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $before = $settings->reserve_expiry_hours === null ? null : (int) $settings->reserve_expiry_hours;
            abort_if($before !== ($data['previous_hours'] === null ? null : (int) $data['previous_hours']), 409, 'El plazo fue modificado. Actualiza antes de guardar.');
            $hours = $data['hours'] === null ? null : (int) $data['hours'];

            $cfg = $settings->configuration ? json_decode($settings->configuration, true) : [];
            if (! is_array($cfg)) {
                $cfg = [];
            }
            $prevDeposit = self::depositPolicy();
            $nextDeposit = [
                'require_deposit_for_new' => array_key_exists('require_deposit_for_new', $data) ? (bool) $data['require_deposit_for_new'] : $prevDeposit['require_deposit_for_new'],
                'deposit_mode' => $data['deposit_mode'] ?? $prevDeposit['deposit_mode'],
                'deposit_value' => array_key_exists('deposit_value', $data) ? number_format((float) $data['deposit_value'], 2, '.', '') : $prevDeposit['deposit_value'],
                'deposit_instructions' => array_key_exists('deposit_instructions', $data) ? (trim((string) $data['deposit_instructions']) ?: self::DEPOSIT_DEFAULTS['deposit_instructions']) : $prevDeposit['deposit_instructions'],
            ];
            $cfg['reservation_deposit'] = $nextDeposit;

            DB::table('business_settings')->where('id', 1)->update([
                'reserve_expiry_hours' => $hours,
                'configuration' => json_encode($cfg, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $audit->record('reservations.policy.updated', BusinessProfile::current(), before: ['hours' => $before, ...$prevDeposit], after: ['hours' => $hours, ...$nextDeposit], actor: $request->user());
        });

        return $this->show();
    }
}
