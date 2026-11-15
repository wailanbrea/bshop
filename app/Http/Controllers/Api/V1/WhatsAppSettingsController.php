<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Domain\Sales\WhatsAppConfiguration;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class WhatsAppSettingsController extends Controller
{
    public function show(WhatsAppConfiguration $config)
    {
        return response()->json(['data' => $config->status(true)]);
    }

    public function status(WhatsAppConfiguration $config)
    {
        return response()->json(['data' => $config->status()]);
    }

    public function update(Request $request, WhatsAppConfiguration $config, AuditTrailService $audit)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'phone_number_id' => ['nullable', 'string', 'regex:/^[0-9]{5,40}$/D'], 'template' => ['nullable', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/D'], 'language' => ['required', 'string', 'regex:/^[a-z]{2,3}(?:_[A-Z]{2})?$/D'], 'graph_version' => ['required', 'string', 'regex:/^v[0-9]{1,2}\.0$/D'], 'body_fields' => ['present', 'array', 'list', 'max:4'], 'body_fields.*' => ['required', 'in:customer_name,invoice_number,total,business_name', 'distinct'], 'access_token' => ['nullable', 'string', 'max:4096'], 'app_secret' => ['nullable', 'string', 'max:512'], 'verify_token' => ['nullable', 'string', 'min:16', 'max:512']]);
        DB::transaction(function () use ($data, $request, $audit) {
            abort_unless(User::whereKey($request->user()->id)->sharedLock()->firstOrFail()->permits('business.manage'), 403);
            $row = DB::table('business_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $settings = json_decode($row->configuration ?: '{}', true, flags: JSON_THROW_ON_ERROR);
            $stored = $settings['whatsapp'] ?? [];
            foreach ($data as $key => $value) {
                if (in_array($key, WhatsAppConfiguration::SECRETS, true)) {
                    if (filled($value)) {
                        $stored[$key] = Crypt::encryptString($value);
                    }
                } else {
                    $stored[$key] = $value;
                }
            }
            $settings['whatsapp'] = $stored;
            DB::table('business_settings')->where('id', 1)->update(['configuration' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $audit->record('whatsapp.configuration.updated', BusinessProfile::current(), after: ['enabled' => (bool) $data['enabled'], 'fields_updated' => array_keys($data)], actor: $request->user());
        });

        return response()->json(['data' => $config->status(true)]);
    }
}
