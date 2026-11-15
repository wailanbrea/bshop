<?php

namespace App\Domain\Sales;

use App\Domain\Business\StoreContext;
use App\Models\SalesOrder;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class FinancialDocument
{
    private function column(string $kind): string
    {
        return match ($kind) {
            'adjustment' => 'return_adjustment_id','credit' => 'credit_application_id',default => abort(404)
        };
    }

    public function source(string $kind, int $id): object
    {
        return DB::table($kind === 'adjustment' ? 'return_adjustments' : 'credit_applications')->where('id', $id)->firstOrFail();
    }

    public function authorizeSource(StoreContext $context, int $orderId, string $kind, int $id): object
    {
        $this->column($kind);
        $source = $this->source($kind, $id);
        $owner = $kind === 'adjustment' ? $source->order_id : $source->target_order_id;
        abort_unless((int) $source->store_id === $context->store()->id && (int) $owner === $orderId, 404);

        return $source;
    }

    private function sorted(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }if (! array_is_list($data)) {
            ksort($data);
        }foreach ($data as &$value) {
            $value = $this->sorted($value);
        }unset($value);

        return $data;
    }

    public function canonical(array $data): string
    {
        return json_encode($this->sorted($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function capture(string $kind, int $id, bool $legacy = false): object
    {
        $column = $this->column($kind);
        $existing = DB::table('financial_documents')->where($column, $id)->first();
        if ($existing) {
            return $existing;
        }
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->capture($kind, $id, $legacy));
        }
        $source = $this->source($kind, $id);
        $orderId = $kind === 'adjustment' ? $source->order_id : $source->target_order_id;
        $order = SalesOrder::findOrFail($orderId);
        $invoice = DB::table('invoices')->where('id', $source->invoice_id)->firstOrFail();
        $business = json_decode($invoice->business_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $customer = OrderInvoice::customer($invoice);
        $data = ['kind' => $kind, 'record_id' => $id, 'reference' => ($kind === 'adjustment' ? 'AJU-' : 'CRE-').str_pad((string) $id, 8, '0', STR_PAD_LEFT), 'issued_at' => CarbonImmutable::parse($source->created_at, config('app.timezone'))->toIso8601String(),
            'store_id' => (int) $source->store_id, 'store_name' => $legacy ? 'Sucursal #'.$source->store_id : Store::findOrFail($source->store_id)->name, 'order_id' => (int) $orderId, 'order_number' => $order->basket_number ?: $orderId, 'invoice_number' => $invoice->invoice_number, 'currency' => $source->currency,
            'business' => $business, 'customer' => $customer, 'amount' => bcadd((string) $source->amount, '0', 4), 'reason' => $source->reason, 'actor_name' => $source->actor_name, 'legacy_capture' => $legacy];
        if ($kind === 'adjustment') {
            $proposal = json_decode($source->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $data['lines'] = $proposal['lines'];
            $data['original_total'] = $proposal['before']['original_total'];
            $data['merchandise_credit'] = bcadd((string) $source->merchandise_credit, '0', 4);
            $data['shipping_credit'] = bcadd((string) $source->shipping_credit, '0', 4);
            $data['net_after'] = bcadd((string) $source->net_after, '0', 4);
            $data['credit_released'] = bcadd((string) $source->credit_released, '0', 4);
            $data['debt_reduction'] = $proposal['debt_reduction'];
            $data['sales_return_id'] = (int) $source->sales_return_id;
        } else {
            $origin = SalesOrder::findOrFail($source->origin_order_id);
            $data['origin_order_id'] = (int) $source->origin_order_id;
            $data['origin_number'] = $origin->basket_number ?: $origin->id;
            $data['payment_id'] = (int) $source->payment_id;
            $data['sources'] = DB::table('credit_application_sources as c')->join('payment_allocation_releases as r', 'r.id', '=', 'c.release_id')->join('return_adjustments as a', 'a.id', '=', 'r.adjustment_id')->where('c.credit_application_id', $id)->orderBy('c.release_id')->get(['r.order_id', 'a.sales_return_id', 'r.adjustment_id', 'c.amount'])->map(fn ($row) => ['order_id' => (int) $row->order_id, 'sales_return_id' => (int) $row->sales_return_id, 'adjustment_id' => (int) $row->adjustment_id, 'amount' => bcadd((string) $row->amount, '0', 4)])->all();
        }
        $json = $this->canonical($data);
        DB::table('financial_documents')->insertOrIgnore(['store_id' => $source->store_id, 'order_id' => $orderId, $column => $id, 'version' => 1, 'snapshot' => $json, 'data_hash' => hash('sha256', $json), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('financial_documents')->where($column, $id)->firstOrFail();
    }

    public function snapshot(object $document): array
    {
        try {
            $data = json_decode($document->snapshot, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(503, 'El snapshot histórico del comprobante requiere restauración.');
        }
        abort_unless(is_array($data) && hash_equals($document->data_hash, hash('sha256', $this->canonical($data))), 503, 'El snapshot histórico del comprobante requiere restauración.');
        $kind = $document->return_adjustment_id ? 'adjustment' : 'credit';
        $id = (int) ($document->return_adjustment_id ?: $document->credit_application_id);
        $source = $this->source($kind, $id);
        $owner = $kind === 'adjustment' ? $source->order_id : $source->target_order_id;
        abort_unless((int) $source->store_id === (int) $document->store_id && (int) $owner === (int) $document->order_id, 503, 'El origen histórico del comprobante requiere restauración.');
        abort_unless(($data['kind'] ?? null) === $kind && ($data['record_id'] ?? null) === $id && ($data['order_id'] ?? null) === (int) $document->order_id && ($data['store_id'] ?? null) === (int) $document->store_id, 503, 'El contexto histórico del comprobante requiere restauración.');

        return $data;
    }

    public function key(string $kind, int $id): string
    {
        $this->column($kind);

        return 'financial-documents/'.$kind.'/'.$id.'/v1.pdf';
    }

    public function ready(string $kind, int $id): object
    {
        $document = DB::table('financial_documents')->where($this->column($kind), $id)->where('status', 'ready')->firstOrFail();
        $this->snapshot($document);
        $disk = Storage::disk('private_invoices');
        $key = $this->key($kind, $id);
        abort_unless($document->object_key === $key && $this->matches($disk, $key, $document), 503, 'El comprobante histórico falta o fue alterado. Restaura su copia original.');

        return $document;
    }

    private function matches($disk, string $key, object $document): bool
    {
        try {
            return $disk->exists($key) && preg_match('/^[a-f0-9]{64}$/D', (string) $document->sha256) && hash_equals($document->sha256, hash_file('sha256', $disk->path($key))) && (int) $document->byte_size === filesize($disk->path($key));
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensure(string $kind, int $id): object
    {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new \LogicException('Render financial PDFs only after the business commit.');
        }
        $this->capture($kind, $id, true);
        $disk = Storage::disk('private_invoices');
        $directory = $disk->path('_locks');
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            abort(503, 'No se pudo crear el bloqueo del comprobante.');
        }
        $handle = fopen($directory.'/financial-'.$kind.'-'.$id.'.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }abort(409, 'El comprobante se está preparando. Reintenta el mismo documento.');
        }
        try {
            $document = DB::table('financial_documents')->where($this->column($kind), $id)->firstOrFail();
            if ($document->status === 'ready') {
                return $this->ready($kind, $id);
            }
            $data = $this->snapshot($document);
            $key = $this->key($kind, $id);
            $temporary = $key.'.pending';
            if ($disk->exists($key)) {
                // Recover a crash after publication only when its prior seal matches.
                abort_unless($document->object_key === $key && $this->matches($disk, $key, $document), 503, 'Hay un archivo histórico sin sello válido. Restaura su metadata original.');
            } else {
                if ($document->object_key !== $key || ! $this->matches($disk, $temporary, $document)) {
                    $bytes = Pdf::loadView('documents.financial', compact('data'))->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans'])->output();
                    abort_unless(str_starts_with($bytes, '%PDF-'), 503, 'No se pudo generar un PDF válido.');
                    $disk->put($temporary, $bytes);
                    $hash = hash('sha256', $bytes);
                    $seal = (object) ['sha256' => $hash, 'byte_size' => strlen($bytes)];
                    abort_unless($this->matches($disk, $temporary, $seal), 503, 'No se pudo verificar el archivo temporal.');
                    DB::table('financial_documents')->where('id', $document->id)->update(['object_key' => $key, 'sha256' => $hash, 'byte_size' => strlen($bytes), 'updated_at' => now()]);
                    $document = DB::table('financial_documents')->where('id', $document->id)->firstOrFail();
                }
                abort_if($disk->exists($key), 503, 'El archivo histórico ya existe y requiere revisión.');
                $disk->move($temporary, $key);
                abort_unless($this->matches($disk, $key, $document), 503, 'No se pudo verificar la publicación del comprobante.');
            }
            DB::table('financial_documents')->where('id', $document->id)->update(['status' => 'ready', 'updated_at' => now()]);

            return $this->ready($kind, $id);
        } catch (\Throwable $error) {
            DB::table('financial_documents')->where($this->column($kind), $id)->where('status', '!=', 'ready')->update(['status' => 'failed', 'updated_at' => now()]);
            throw $error;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
