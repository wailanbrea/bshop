<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class WhatsAppConfiguration
{
    public const SECRETS = ['access_token', 'app_secret', 'verify_token'];

    public function resolve(): array
    {
        $config = config('whatsapp');
        $settings = json_decode(DB::table('business_settings')->where('id', 1)->value('configuration') ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        foreach (($settings['whatsapp'] ?? []) as $key => $value) {
            if (in_array($key, self::SECRETS, true)) {
                try {
                    $config[$key] = Crypt::decryptString($value);
                } catch (\Throwable) {
                    $config[$key] = null;
                }
            } else {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    public function status(bool $admin = false): array
    {
        $c = $this->resolve();
        $missing = [];
        foreach (['access_token', 'phone_number_id', 'app_secret', 'verify_token', 'template', 'language', 'graph_version'] as $key) {
            if (! filled($c[$key] ?? null)) {
                $missing[] = $key;
            }
        }
        $result = ['enabled' => (bool) $c['enabled'], 'ready' => (bool) $c['enabled'] && ! $missing, 'provider' => 'meta', 'reason' => ! $c['enabled'] ? 'WhatsApp está desactivado; es opcional.' : ($missing ? 'Falta completar la configuración de WhatsApp.' : 'Configuración completa; el resultado real se confirma al enviar y por webhook.')];
        if ($admin) {
            $result += collect($c)->except(self::SECRETS)->all();
            $result['missing'] = $missing;
            foreach (self::SECRETS as $key) {
                $result['has_'.$key] = filled($c[$key] ?? null);
            }
            $result['webhook_url'] = url('/webhooks/whatsapp');
        }

        return $result;
    }
}
