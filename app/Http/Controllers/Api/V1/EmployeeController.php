<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Domain\Sales\OrderSettlement;
use App\Domain\Sales\PaymentDistribution;
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
        $plans = DB::table('employee_compensation_plans')->where('employee_id', $employee->id)->orderByDesc('effective_from')->orderByDesc('id')->get();
        $currency = BusinessProfile::current()->default_currency;
        $ordersCount = 0;
        $salesNet = '0.0000';
        $collectedNet = '0.0000';
        $earnedCommission = '0.0000';

        $orders = DB::table('orders as o')
            ->leftJoin('invoice_orders as io', 'io.order_id', '=', 'o.id')
            ->where('o.store_id', $context->store()->id)
            ->where('o.seller_employee_id', $employee->id)
            ->whereNotIn('o.status', ['draft', 'cancelled'])
            ->select('o.*', 'io.invoice_id')
            ->cursor();

        foreach ($orders as $order) {
            $ordersCount++;
            $pos = OrderSettlement::position($order, $order->invoice_id);
            $salesNet = bcadd($salesNet, $pos['net_total'], 4);
            $collectedNet = bcadd($collectedNet, $pos['paid_total'], 4);
            if (bccomp($pos['paid_total'], '0', 4) > 0 && bccomp($pos['net_total'], '0', 4) > 0) {
                $rate = DB::table('order_items')
                    ->where('order_id', $order->id)
                    ->whereNull('removed_at')
                    ->whereNotNull('commission_rate_snapshot')
                    ->value('commission_rate_snapshot');
                if ($rate !== null && bccomp((string) $rate, '0', 4) > 0) {
                    $paidRatio = bccomp($pos['paid_total'], $pos['net_total'], 4) >= 0 ? '1.00000000' : bcdiv($pos['paid_total'], $pos['net_total'], 8);
                    $merchandiseBase = bcsub(bcsub((string) $order->subtotal, (string) $order->discount_total, 4), $pos['return_credit_total'], 4);
                    if (bccomp($merchandiseBase, '0', 4) > 0) {
                        $collectedMerchandise = bcadd(bcmul($merchandiseBase, $paidRatio, 8), '0', 2);
                        $comm = PaymentDistribution::commission($collectedMerchandise, (string) $rate);
                        $earnedCommission = bcadd($earnedCommission, $comm, 4);
                    }
                }
            }
        }

        $disbursements = DB::table('payroll_disbursements as pd')
            ->join('payroll_lines as pl', 'pl.id', '=', 'pd.payroll_line_id')
            ->join('payroll_periods as pp', 'pp.id', '=', 'pl.payroll_period_id')
            ->where('pd.employee_id', $employee->id)
            ->where('pp.store_id', $context->store()->id)
            ->orderByDesc('pd.paid_at')
            ->orderByDesc('pd.id')
            ->select('pd.id', 'pd.amount', 'pd.currency', 'pd.payment_method', 'pd.reference', 'pd.paid_at')
            ->get()
            ->map(function ($row) {
                $row->amount = bcadd((string) $row->amount, '0', 4);

                return $row;
            });

        $disbursedTotal = '0.0000';
        foreach ($disbursements as $disb) {
            $disbursedTotal = bcadd($disbursedTotal, $disb->amount, 4);
        }
        $pendingCommission = bcsub($earnedCommission, $disbursedTotal, 4);
        if (bccomp($pendingCommission, '0', 4) < 0) {
            $pendingCommission = '0.0000';
        }

        return response()->json([
            'data' => $plans,
            'disbursements' => $disbursements,
            'summary' => [
                'currency' => $currency,
                'orders_count' => $ordersCount,
                'sales_net' => $salesNet,
                'collected_net' => $collectedNet,
                'earned_commission' => $earnedCommission,
                'disbursed_total' => $disbursedTotal,
                'pending_commission' => $pendingCommission,
            ],
        ]);
    }

    public function createDisbursement(Request $request, StoreContext $context, IdempotentAction $once, AuditTrailService $audit)
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'amount' => ['required', new DecimalAmount],
            'payment_method' => ['required', 'in:cash,bank_transfer,card'],
            'reference' => ['nullable', 'string', 'max:160'],
        ]);
        $id = (int) $request->route('employee');
        $key = $data['request_key'];
        unset($data['request_key']);
        $normalizedAmount = DecimalAmount::normalize($data['amount']);
        if (bccomp($normalizedAmount, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => 'El pago debe ser mayor que cero.']);
        }
        $result = $once->run('employees.disbursement.create', $key, [$request->user()->id, $context->store()->id, $id], $data, fn () => $this->authorizeWrite($request, $context), function () use ($data, $normalizedAmount, $id, $key, $request, $context, $audit) {
            $this->employee($context, $id);
            $employee = Employee::whereKey($id)->lockForUpdate()->firstOrFail();
            $profile = BusinessProfile::current();
            $currency = $profile->default_currency;
            $today = now($profile->timezone)->toDateString();
            $scopeKey = 'store:'.$context->store()->id;
            $period = DB::table('payroll_periods')
                ->where('scope_key', $scopeKey)
                ->where('starts_on', $today)
                ->where('ends_on', $today)
                ->lockForUpdate()
                ->first();
            $periodId = $period ? (int) $period->id : DB::table('payroll_periods')->insertGetId([
                'store_id' => $context->store()->id,
                'scope_key' => $scopeKey,
                'currency' => $currency,
                'starts_on' => $today,
                'ends_on' => $today,
                'status' => 'paid',
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $line = DB::table('payroll_lines')
                ->where('payroll_period_id', $periodId)
                ->where('employee_id', $id)
                ->lockForUpdate()
                ->first();
            if ($line) {
                $lineId = (int) $line->id;
                DB::table('payroll_lines')->where('id', $lineId)->update([
                    'commission_amount' => bcadd((string) $line->commission_amount, $normalizedAmount, 4),
                    'total_payable' => bcadd((string) $line->total_payable, $normalizedAmount, 4),
                    'updated_at' => now(),
                ]);
            } else {
                $lineId = DB::table('payroll_lines')->insertGetId([
                    'payroll_period_id' => $periodId,
                    'employee_id' => $id,
                    'compensation_type_snapshot' => 'commission',
                    'currency' => $currency,
                    'salary_amount' => '0.0000',
                    'commission_amount' => $normalizedAmount,
                    'adjustments' => '0.0000',
                    'deductions' => '0.0000',
                    'total_payable' => $normalizedAmount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $disbId = DB::table('payroll_disbursements')->insertGetId([
                'payroll_line_id' => $lineId,
                'employee_id' => $id,
                'amount' => $normalizedAmount,
                'currency' => $currency,
                'payment_method' => $data['payment_method'],
                'reference' => isset($data['reference']) && trim($data['reference']) !== '' ? trim($data['reference']) : null,
                'event_key' => 'disbursement:'.$key,
                'paid_at' => now(),
                'created_at' => now(),
            ]);
            $audit->record('employee.disbursement.created', $employee, after: ['disbursement_id' => $disbId, 'amount' => $normalizedAmount, 'payment_method' => $data['payment_method']], actor: $request->user());

            return ['disbursement_id' => $disbId];
        });
        $this->employee($context, $id);
        $disbRow = DB::table('payroll_disbursements')->where('employee_id', $id)->where('id', $result['disbursement_id'])->firstOrFail();
        $disbRow->amount = bcadd((string) $disbRow->amount, '0', 4);

        return response()->json(['data' => ['disbursement' => $disbRow, 'replay' => $result['replay']]], $result['replay'] ? 200 : 201);
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
