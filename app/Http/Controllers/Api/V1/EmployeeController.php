<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\Employee;
use App\Models\User;
use App\Rules\DecimalAmount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    private function authorizeWrite(Request $request, StoreContext $context): void
    {
        abort_unless(User::whereKey($request->user()->id)->sharedLock()->firstOrFail()->permits('members.manage'), 403);
        abort_unless(DB::table('store_user')->where('user_id', $request->user()->id)->where('store_id', $context->store()->id)->sharedLock()->first(), 404);
    }

    private function employee(StoreContext $context, int $id): Employee
    {
        return Employee::whereKey($id)->whereExists(fn ($q) => $q->selectRaw('1')->from('employee_store_assignments')->whereColumn('employee_store_assignments.employee_id', 'employees.id')->where('employee_store_assignments.store_id', $context->store()->id))->firstOrFail();
    }

    public function index(Request $request, StoreContext $context)
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:active,inactive,all']]);
        $status = $data['status'] ?? 'all';
        $query = Employee::whereExists(fn ($q) => $q->selectRaw('1')->from('employee_store_assignments')->whereColumn('employee_store_assignments.employee_id', 'employees.id')->where('employee_store_assignments.store_id', $context->store()->id));
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $page = $query->orderBy('full_name')->orderBy('id')->paginate(15);
        $ids = collect($page->items())->pluck('id');
        $assignments = DB::table('employee_store_assignments')->whereIn('employee_id', $ids)->get()->groupBy('employee_id');
        $items = collect($page->items())->map(function (Employee $emp) use ($assignments) {
            $arr = $emp->toArray();
            $arr['store_ids'] = ($assignments->get($emp->id) ?? collect())->pluck('store_id')->map(fn ($id) => (int) $id)->values()->all();

            return $arr;
        })->all();

        return response()->json(['data' => $items, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function create(Request $request, StoreContext $context, IdempotentAction $once, AuditTrailService $audit)
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'employee_code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/D'], 'full_name' => ['required', 'string', 'max:180'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:190']]);
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['employee_code'] = strtoupper($data['employee_code']);
        $data['full_name'] = trim($data['full_name']);
        if ($data['full_name'] === '') {
            throw ValidationException::withMessages(['full_name' => 'Indica el nombre del vendedor.']);
        }
        $result = $once->run('employees.create', $key, [$request->user()->id, $context->store()->id], $data, fn () => $this->authorizeWrite($request, $context), function () use ($data, $request, $context, $audit) {
            DB::table('business_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_if(Employee::where('employee_code', $data['employee_code'])->lockForUpdate()->first(), 409, 'El código de vendedor ya existe.');
            $employee = Employee::create([...$data, 'home_store_id' => $context->store()->id, 'status' => 'active', 'can_sell' => true]);
            DB::table('employee_store_assignments')->insert(['employee_id' => $employee->id, 'store_id' => $context->store()->id, 'created_at' => now()]);
            $audit->record('employee.created', $employee, after: ['employee_id' => $employee->id, 'store_id' => $context->store()->id], actor: $request->user());

            return ['employee_id' => $employee->id];
        });

        return response()->json(['data' => ['employee' => $this->employee($context, $result['employee_id']), 'replay' => $result['replay']]], $result['replay'] ? 200 : 201);
    }

    public function update(Request $request, StoreContext $context, IdempotentAction $once, AuditTrailService $audit)
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'employee_code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'full_name' => ['required', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'status' => ['required', 'in:active,inactive'],
            'store_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'store_ids.*' => ['integer', 'min:1'],
        ]);
        $id = (int) $request->route('employee');
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['employee_code'] = strtoupper($data['employee_code']);
        $data['full_name'] = trim($data['full_name']);
        if ($data['full_name'] === '') {
            throw ValidationException::withMessages(['full_name' => 'Indica el nombre del vendedor.']);
        }
        $result = $once->run('employees.update', $key, [$request->user()->id, $context->store()->id, $id], $data, fn () => $this->authorizeWrite($request, $context), function () use ($data, $id, $request, $context, $audit) {
            DB::table('business_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $this->employee($context, $id);
            $employee = Employee::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if(Employee::where('employee_code', $data['employee_code'])->where('id', '!=', $id)->lockForUpdate()->first(), 409, 'El código de vendedor ya pertenece a otro registro.');
            $storeIds = isset($data['store_ids']) ? array_values(array_unique(array_map('intval', $data['store_ids']))) : null;
            if ($storeIds !== null) {
                $allowed = DB::table('store_user')->where('user_id', $request->user()->id)->whereIn('store_id', $storeIds)->lockForUpdate()->pluck('store_id')->map(fn ($s) => (int) $s)->all();
                abort_unless(count($allowed) === count($storeIds) && in_array($context->store()->id, $storeIds, true), 403, 'Debes conservar acceso a la sucursal actual y solo asignar sucursales autorizadas.');
            }
            $before = $employee->only(['employee_code', 'full_name', 'phone', 'email', 'status', 'can_sell']);
            $employee->fill([
                'employee_code' => $data['employee_code'],
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'status' => $data['status'],
                'can_sell' => $data['status'] === 'active',
            ]);
            $employee->save();
            if ($storeIds !== null) {
                DB::table('employee_store_assignments')->where('employee_id', $id)->whereNotIn('store_id', $storeIds)->delete();
                $existing = DB::table('employee_store_assignments')->where('employee_id', $id)->pluck('store_id')->map(fn ($s) => (int) $s)->all();
                foreach (array_diff($storeIds, $existing) as $storeId) {
                    DB::table('employee_store_assignments')->insert(['employee_id' => $id, 'store_id' => $storeId, 'created_at' => now()]);
                }
            }
            $audit->record('employee.updated', $employee, before: $before, after: $employee->only(['employee_code', 'full_name', 'phone', 'email', 'status', 'can_sell']), actor: $request->user());

            return ['employee_id' => $employee->id];
        });
        $updated = $this->employee($context, $result['employee_id']);
        $arr = $updated->toArray();
        $arr['store_ids'] = DB::table('employee_store_assignments')->where('employee_id', $updated->id)->pluck('store_id')->map(fn ($s) => (int) $s)->values()->all();

        return response()->json(['data' => ['employee' => $arr, 'replay' => $result['replay']]]);
    }

    public function plans(Request $request, StoreContext $context)
    {
        $employee = $this->employee($context, (int) $request->route('employee'));

        return response()->json(['data' => DB::table('employee_compensation_plans')->where('employee_id', $employee->id)->orderByDesc('effective_from')->orderByDesc('id')->get()]);
    }

    public function createPlan(Request $request, StoreContext $context, IdempotentAction $once, AuditTrailService $audit)
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'pay_type' => ['required', 'in:salary,commission'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'end_open_plan' => ['sometimes', 'boolean'],
            'fixed_salary' => ['required_if:pay_type,salary', 'prohibited_unless:pay_type,salary', new DecimalAmount],
            'salary_period' => ['required_if:pay_type,salary', 'prohibited_unless:pay_type,salary', 'in:weekly,biweekly,monthly'],
            'commission_rate' => ['required_if:pay_type,commission', 'prohibited_unless:pay_type,commission', new DecimalAmount],
        ]);
        $id = (int) $request->route('employee');
        $key = $data['request_key'];
        unset($data['request_key']);
        if ($data['pay_type'] === 'commission' && bccomp($data['commission_rate'], '100', 4) > 0) {
            throw ValidationException::withMessages(['commission_rate' => 'El porcentaje no puede superar 100.']);
        }
        $result = $once->run('employees.plan.create', $key, [$request->user()->id, $context->store()->id, $id], $data, fn () => $this->authorizeWrite($request, $context), function () use ($data, $id, $request, $context, $audit) {
            $this->employee($context, $id);
            $employee = Employee::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($employee->status === 'active', 409, 'El vendedor no está activo.');
            $profile = BusinessProfile::current();
            $existingPlan = DB::table('employee_compensation_plans')->where('employee_id', $id)->lockForUpdate()->first();
            abort_if($existingPlan && $data['effective_from'] <= now($profile->timezone)->toDateString(), 409, 'Los contratos posteriores deben iniciar en una fecha futura.');
            if ($data['end_open_plan'] ?? false) {
                $openPlan = DB::table('employee_compensation_plans')->where('employee_id', $id)->whereNull('effective_to')->lockForUpdate()->first();
                abort_unless($openPlan && $data['effective_from'] > $openPlan->effective_from, 409, 'No hay un contrato abierto anterior que pueda finalizarse.');
                $end = CarbonImmutable::parse($data['effective_from'], 'UTC')->subDay()->toDateString();
                DB::table('employee_compensation_plans')->where('id', $openPlan->id)->update(['effective_to' => $end, 'updated_at' => now()]);
                $audit->record('employee.plan.end_scheduled', $employee, before: ['plan_id' => $openPlan->id, 'effective_to' => null], after: ['plan_id' => $openPlan->id, 'effective_to' => $end], actor: $request->user());
            }
            $overlap = DB::table('employee_compensation_plans')->where('employee_id', $id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $data['effective_from']))
                ->when($data['effective_to'] ?? null, fn ($q, $end) => $q->where('effective_from', '<=', $end))
                ->lockForUpdate()->first();
            abort_if($overlap, 409, 'El contrato se solapa con otro período.');
            $commission = $data['pay_type'] === 'commission';
            $planId = DB::table('employee_compensation_plans')->insertGetId(['employee_id' => $id, 'pay_type' => $data['pay_type'], 'currency' => $profile->default_currency,
                'fixed_salary' => $commission ? null : DecimalAmount::normalize($data['fixed_salary']), 'salary_period' => $commission ? null : $data['salary_period'],
                'commission_rate' => $commission ? DecimalAmount::normalize($data['commission_rate']) : null, 'commission_base' => $commission ? 'net_items' : null, 'commission_trigger' => $commission ? 'payment' : null,
                'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            $audit->record('employee.plan.created', $employee, after: ['plan_id' => $planId, 'pay_type' => $data['pay_type'], 'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null], actor: $request->user());

            return ['plan_id' => $planId];
        });
        $this->employee($context, $id);

        return response()->json(['data' => ['plan' => DB::table('employee_compensation_plans')->where('employee_id', $id)->where('id', $result['plan_id'])->firstOrFail(), 'replay' => $result['replay']]], $result['replay'] ? 200 : 201);
    }
}
