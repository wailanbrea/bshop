<?php

namespace App\Domain\Business;

use Illuminate\Support\Facades\DB;

final class IdempotentAction
{
    public function run(string $operation, string $key, array $identity, array $payload, callable $authorize, callable $action): array
    {
        $hash = hash('sha256', json_encode([$identity, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($operation, $key, $hash, $authorize, $action, $identity) {
            DB::table('idempotency_requests')->insertOrIgnore(['operation' => $operation, 'request_key' => strtolower($key), 'request_hash' => $hash, 'status' => 'processing', 'created_at' => now()]);
            $record = DB::table('idempotency_requests')->where('operation', $operation)->where('request_key', strtolower($key))->lockForUpdate()->first();
            abort_unless(hash_equals($record->request_hash, $hash), 409, 'La clave de petición pertenece a otros datos. Reintenta la operación original.');
            $authorize();
            if ($record->status === 'completed') {
                $result = json_decode($record->response_data, true, flags: JSON_THROW_ON_ERROR);
                unset($result['_identity']);

                return [...$result, 'replay' => true];
            }
            $result = $action();
            DB::table('idempotency_requests')->where('id', $record->id)->update(['status' => 'completed', 'response_data' => json_encode([...$result, '_identity' => $identity], JSON_THROW_ON_ERROR), 'completed_at' => now()]);

            return [...$result, 'replay' => false];
        }, 3);
    }
}
