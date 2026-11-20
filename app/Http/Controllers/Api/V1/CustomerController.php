<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function show(Request $request, StoreContext $context)
    {
        return response()->json(['data' => Customer::where('store_id', $context->store()->id)->findOrFail($request->route('customer'))]);
    }

    public function status(Request $request, StoreContext $context, IdempotentAction $once, AuditTrailService $audit)
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'status' => ['required', 'in:active,archived']]);
        $id = (int) $request->route('customer');
        $key = $data['request_key'];
        unset($data['request_key']);
        $result = $once->run('customers.status', $key, [$request->user()->id, $context->store()->id, $id], $data, function () use ($request, $context) {
            abort_unless(User::whereKey($request->user()->id)->sharedLock()->firstOrFail()->permits('customers.manage'), 403);
            abort_unless(DB::table('store_user')->where('user_id', $request->user()->id)->where('store_id', $context->store()->id)->sharedLock()->first(), 404);
        }, function () use ($id, $data, $request, $audit, $context) {
            $customer = Customer::where('store_id', $context->store()->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($customer->lock_version !== (int) $data['version'], 409, 'El cliente cambió. Actualiza su ficha.');
            if ($customer->status !== $data['status']) {
                $before = $customer->only(['status', 'lock_version']);
                $customer->status = $data['status'];
                $customer->lock_version++;
                $customer->save();
                $audit->record($data['status'] === 'archived' ? 'customer.archived' : 'customer.reactivated', $customer, before: $before, after: $customer->only(['status', 'lock_version']), actor: $request->user());
            }

            return ['customer' => $customer->toArray()];
        });

        return response()->json(['data' => $result]);
    }

    public function update(Request $request, StoreContext $context, IdempotentAction $once, AuditTrailService $audit)
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'full_name' => ['required', 'string', 'max:180'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:190'], 'address' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $id = (int) $request->route('customer');
        $result = $once->run('customers.update', $data['request_key'], [$request->user()->id, $context->store()->id, $id], $data, function () use ($request, $context) {
            abort_unless(User::whereKey($request->user()->id)->sharedLock()->firstOrFail()->permits('customers.manage'), 403);
            abort_unless(DB::table('store_user')->where('user_id', $request->user()->id)->where('store_id', $context->store()->id)->sharedLock()->first(), 404);
        }, function () use ($id, $data, $request, $audit, $context) {
            $customer = Customer::where('store_id', $context->store()->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($customer->lock_version !== (int) $data['version'], 409, 'El cliente cambió. Actualiza su ficha.');
            $before = $customer->only(['full_name', 'phone', 'email', 'address', 'notes']);
            $customer->fill(collect($data)->except(['request_key', 'version'])->all());
            $customer->lock_version++;
            $customer->save();
            $audit->record('customer.updated', $customer, before: $before, after: $customer->only(['full_name', 'phone', 'email', 'address', 'notes']), actor: $request->user());

            return ['customer' => $customer->toArray()];
        });

        return response()->json(['data' => $result]);
    }

    public function requestStatus(Request $request, StoreContext $context)
    {
        $data = $request->validate(['action' => ['nullable', 'in:create,update,status']]);
        $record = DB::table('idempotency_requests')->where('operation', 'customers.'.($data['action'] ?? 'create'))->where('request_key', strtolower($request->route('key')))->where('status', 'completed')->firstOrFail();
        $result = json_decode($record->response_data, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($result['_identity'][0] ?? null) === $request->user()->id && ($result['_identity'][1] ?? null) === $context->store()->id, 404);

        return response()->json(['data' => ['customer' => Customer::where('store_id', $context->store()->id)->findOrFail($result['customer']['id']), 'replay' => true]]);
    }

    public function index(Request $request, StoreContext $context)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:active,archived,all']]);
        $query = Customer::query()->where('store_id', $context->store()->id);
        if (($data['status'] ?? 'active') !== 'all') {
            $query->where('status', $data['status'] ?? 'active');
        }
        if (! empty($data['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("full_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("phone LIKE ? ESCAPE '!'", [$pattern]));
        }
        $page = $query->orderBy('full_name')->paginate(15);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function create(Request $request, StoreContext $context, IdempotentAction $idempotency, AuditTrailService $audit)
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'full_name' => ['required', 'string', 'max:180'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:190'], 'address' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $key = $data['request_key'];
        unset($data['request_key']);
        $result = $idempotency->run('customers.create', $key, [$request->user()->id, $context->store()->id], $data,
            function () use ($request, $context) {
                abort_unless(User::whereKey($request->user()->id)->sharedLock()->firstOrFail()->permits('customers.manage'), 403);
                abort_unless(DB::table('store_user')->where('user_id', $request->user()->id)->where('store_id', $context->store()->id)->sharedLock()->first(), 404);
            }, function () use ($data, $audit, $request, $context) {
                $customer = Customer::create([...$data, 'store_id' => $context->store()->id]);
                $audit->record('customer.created', $customer, actor: $request->user());

                return ['customer' => $customer->toArray()];
            });

        return response()->json(['data' => $result], $result['replay'] ? 200 : 201);
    }

    public function import(Request $request, StoreContext $context, AuditTrailService $audit)
    {
        $data = $request->validate([
            'default_country_code' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{0,3}$/D'],
            'contacts' => ['required', 'array', 'min:1', 'max:500'],
            'contacts.*.full_name' => ['required', 'string', 'max:180'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.email' => ['nullable', 'email', 'max:190'],
            'contacts.*.notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $storeId = $context->store()->id;
        $defaultCode = $data['default_country_code'] ?? '+1';

        $normalizePhone = function (?string $raw) use ($defaultCode): ?string {
            if ($raw === null || trim($raw) === '') {
                return null;
            }
            $trimmed = trim($raw);
            $hasPlus = str_starts_with($trimmed, '+');
            $digits = preg_replace('/\D+/', '', $trimmed);
            if ($digits === '' || strlen($digits) < 7) {
                return $trimmed;
            }
            if ($hasPlus) {
                return '+'.$digits;
            }
            if (strlen($digits) === 10 && $defaultCode === '+1') {
                return '+1'.$digits;
            }
            if (strlen($digits) === 11 && str_starts_with($digits, '1') && $defaultCode === '+1') {
                return '+'.$digits;
            }

            return $defaultCode.$digits;
        };

        $summary = DB::transaction(function () use ($data, $storeId, $normalizePhone, $audit, $request, $context) {
            abort_unless(User::whereKey($request->user()->id)->sharedLock()->firstOrFail()->permits('customers.manage'), 403);
            abort_unless(DB::table('store_user')->where('user_id', $request->user()->id)->where('store_id', $storeId)->sharedLock()->first(), 404);

            $existing = Customer::where('store_id', $storeId)->get(['id', 'full_name', 'phone']);
            $existingPhones = [];
            $existingNames = [];
            foreach ($existing as $c) {
                $digits = preg_replace('/\D+/', '', (string) $c->phone);
                if ($digits !== '') {
                    $existingPhones[$digits] = true;
                }
                $existingNames[mb_strtolower(trim($c->full_name)).'|'.$digits] = true;
            }

            $created = [];
            $skipped = 0;

            foreach ($data['contacts'] as $row) {
                $name = trim($row['full_name']);
                if ($name === '') {
                    $skipped++;
                    continue;
                }
                $phone = $normalizePhone($row['phone'] ?? null);
                $digits = preg_replace('/\D+/', '', (string) $phone);
                $nameKey = mb_strtolower($name).'|'.$digits;

                if (($digits !== '' && isset($existingPhones[$digits])) || isset($existingNames[$nameKey])) {
                    $skipped++;
                    continue;
                }

                $customer = Customer::create([
                    'store_id' => $storeId,
                    'full_name' => $name,
                    'phone' => $phone,
                    'email' => ! empty($row['email']) ? trim($row['email']) : null,
                    'notes' => ! empty($row['notes']) ? trim($row['notes']) : 'Importado desde contactos de WhatsApp',
                ]);
                if ($digits !== '') {
                    $existingPhones[$digits] = true;
                }
                $existingNames[$nameKey] = true;
                $created[] = $customer->toArray();
            }

            if (count($created) > 0) {
                $audit->record('customer.batch_imported', $context->store(), after: ['store_id' => $storeId, 'created' => count($created), 'skipped' => $skipped], actor: $request->user());
            }

            return [
                'created_count' => count($created),
                'skipped_count' => $skipped,
                'total_submitted' => count($data['contacts']),
                'customers' => array_slice($created, 0, 20),
            ];
        });

        return response()->json(['data' => $summary], 201);
    }
}
