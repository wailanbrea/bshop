<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WhatsAppConfiguration
{
    public const SECRETS = ['access_token', 'app_secret', 'verify_token'];

    public function resolve(?int $storeId = null): array
    {
        $config = config('whatsapp');
        if ($storeId !== null && Schema::hasTable('store_whatsapp_configs')) {
            $row = DB::table('store_whatsapp_configs')->where('store_id', $storeId)->first();
            if ($row) {
                $config['enabled'] = (bool) $row->enabled;
                $config['phone_number_id'] = $row->phone_number_id;
                $config['display_phone'] = $row->display_phone ?? null;
                $config['template'] = $row->template;
                $config['language'] = $row->language ?: ($config['language'] ?? 'es');
                $config['graph_version'] = $row->graph_version ?: ($config['graph_version'] ?? 'v23.0');
                $config['body_fields'] = $row->body_fields ? json_decode($row->body_fields, true, flags: JSON_THROW_ON_ERROR) : ($config['body_fields'] ?? ['customer_name', 'invoice_number', 'total']);
                foreach (self::SECRETS as $secretKey) {
                    $col = $secretKey.'_encrypted';
                    if (filled($row->$col ?? null)) {
                        try {
                            $config[$secretKey] = Crypt::decryptString($row->$col);
                        } catch (\Throwable) {
                            $config[$secretKey] = null;
                        }
                    } else {
                        $config[$secretKey] = null;
                    }
                }

                return $config;
            }
            // If this store has no dedicated row yet, return disabled/empty defaults (strict tenant isolation — never leak another store's credentials!).
            $primaryStoreId = DB::table('stores')->orderBy('id')->value('id');
            if ($primaryStoreId === null || (int) $primaryStoreId !== $storeId) {
                $config['enabled'] = false;
                $config['phone_number_id'] = null;
                $config['template'] = null;
                foreach (self::SECRETS as $secretKey) {
                    $config[$secretKey] = null;
                }

                return $config;
            }
        }

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

    public function status(bool $admin = false, ?int $storeId = null): array
    {
        $c = $this->resolve($storeId);
        $missing = [];
        foreach (['access_token', 'phone_number_id', 'app_secret', 'verify_token', 'template', 'language', 'graph_version'] as $key) {
            if (! filled($c[$key] ?? null)) {
                $missing[] = $key;
            }
        }
        $result = ['enabled' => (bool) $c['enabled'], 'ready' => (bool) $c['enabled'] && ! $missing, 'provider' => 'meta', 'store_id' => $storeId, 'display_phone' => $c['display_phone'] ?? null, 'reason' => ! $c['enabled'] ? 'WhatsApp está desactivado; es opcional.' : ($missing ? 'Falta completar la configuración de WhatsApp.' : 'Configuración completa; el resultado real se confirma al enviar y por webhook.')];
        if ($admin) {
            $result += collect($c)->except(self::SECRETS)->all();
            $result['missing'] = $missing;
            foreach (self::SECRETS as $key) {
                $result['has_'.$key] = filled($c[$key] ?? null);
            }
            $result['app_secret_is_placeholder'] = str_starts_with((string) ($c['app_secret'] ?? ''), 'temp_');
            $result['webhook_url'] = url('/webhooks/whatsapp');
        }

        return $result;
    }
}
