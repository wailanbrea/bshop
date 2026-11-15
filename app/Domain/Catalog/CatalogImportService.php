<?php

namespace App\Domain\Catalog;

use App\Domain\Business\AuditTrailService;
use App\Models\CatalogImport;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogImportService
{
    public function __construct(private CsvCatalogReader $reader, private CatalogImportValidator $validator, private ProductService $products, private AuditTrailService $audit) {}

    private function actor(User $user, ?CatalogImport $batch = null): User
    {
        $actor = $user->fresh();
        abort_unless($actor?->permits('catalog.manage'), 403);
        if ($batch) {
            abort_unless($batch->created_by_user_id === $actor->id, 404);
            abort_if($batch->requires_costs && ! $actor->permits('catalog.costs'), 403, 'Este lote contiene costos y requiere el permiso correspondiente.');
        }

        return $actor;
    }

    public function preview(UploadedFile $file, User $user): CatalogImport
    {
        $actor = $this->actor($user);
        $bytes = file_get_contents($file->getRealPath());
        $payload = $this->validator->validate($this->reader->read($bytes), $actor);
        $filename = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', mb_convert_encoding(basename(str_replace('\\', '/', $file->getClientOriginalName())), 'UTF-8', 'UTF-8')));

        return DB::transaction(function () use ($actor, $payload, $filename, $bytes) {
            $batch = CatalogImport::create([
                'created_by_user_id' => $actor->id, 'filename' => mb_substr($filename !== '' ? $filename : 'catalogo.csv', 0, 180),
                'source_sha256' => hash('sha256', $bytes), 'status' => 'preview', 'row_count' => count($payload['rows']), 'product_count' => count($payload['groups']),
                'error_count' => $this->errorCount($payload), 'requires_costs' => $actor->permits('catalog.costs') && $this->hasCosts($payload),
                'payload' => $payload, 'expires_at' => now()->addDay(),
            ]);
            $this->audit->record('catalog.import.previewed', $batch, after: $this->summary($batch), actor: $actor);

            return $batch;
        }, 3);
    }

    public function owned(string $id, User $user, bool $lock = false): CatalogImport
    {
        $batch = CatalogImport::where('created_by_user_id', $user->id)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        $this->actor($user, $batch);

        return $batch;
    }

    private function available(CatalogImport $batch): void
    {
        abort_if($batch->status !== 'preview', 409, 'El lote ya no es una vista previa.');
        abort_if($batch->expires_at->isPast(), 410, 'La vista previa venció. Sube el archivo para generar otra.');
    }

    public function revalidate(string $id, User $user): CatalogImport
    {
        return DB::transaction(function () use ($id, $user) {
            $batch = $this->owned($id, $user, true);
            $this->available($batch);
            $actor = $this->actor($user, $batch);
            $this->validateBatch($batch, $actor);

            return $batch;
        }, 3);
    }

    private function validateBatch(CatalogImport $batch, User $actor): void
    {
        $payload = $this->validator->validate($batch->payload, $actor);
        $batch->payload = $payload;
        $batch->error_count = $this->errorCount($payload);
        $batch->requires_costs = $actor->permits('catalog.costs') && $this->hasCosts($payload);
        $batch->save();
    }

    public function confirm(string $id, User $user): array
    {
        try {
            return DB::transaction(function () use ($id, $user) {
                $batch = $this->owned($id, $user, true);
                $actor = $this->actor($user, $batch);
                if ($batch->status === 'committed') {
                    return ['batch' => $batch, 'invalid' => false];
                }
                $this->available($batch);
                $this->validateBatch($batch, $actor);
                if ($batch->error_count > 0) {
                    return ['batch' => $batch, 'invalid' => true];
                }
                $created = [];
                foreach ($batch->payload['groups'] as $group) {
                    $product = $this->products->create($group['data'], $actor);
                    $created[] = ['id' => $product->id, 'name' => $product->name, 'fingerprint' => $this->fingerprint($product)];
                }
                $batch->result = ['products' => $created];
                $batch->status = 'committed';
                $batch->committed_at = now();
                $batch->save();
                $this->audit->record('catalog.import.committed', $batch, after: $this->summary($batch), actor: $actor);

                return ['batch' => $batch, 'invalid' => false];
            }, 3);
        } catch (ValidationException $failure) {
            // A reference or unique SKU may change between validation and an INSERT.
            // The complete transaction has rolled back; refresh row errors without partial products.
            $batch = $this->revalidate($id, $user);
            if ($batch->error_count === 0) {
                throw $failure;
            }

            return ['batch' => $batch, 'invalid' => true];
        }
    }

    public function revert(string $id, User $user): CatalogImport
    {
        return DB::transaction(function () use ($id, $user) {
            $batch = $this->owned($id, $user, true);
            $actor = $this->actor($user, $batch);
            if ($batch->status === 'reverted') {
                return $batch;
            }
            abort_if($batch->status !== 'committed', 409, 'Sólo se puede revertir un lote importado.');
            $snapshots = collect($batch->result['products'])->sortBy('id');
            $ids = $snapshots->pluck('id')->all();
            $products = Product::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($snapshots as $snapshot) {
                $product = $products->get($snapshot['id']);
                abort_unless($product && ! $product->trashed() && hash_equals($snapshot['fingerprint'], $this->fingerprint($product)), 409, 'El lote tiene productos editados. No se revirtió ningún producto.');
                $variants = $product->variants()->withTrashed()->orderBy('id')->lockForUpdate()->pluck('id');
                DB::table('stock_balances')->whereIn('variant_id', $variants)->orderBy('warehouse_id')->orderBy('variant_id')->lockForUpdate()->get();
                $used = DB::table('inventory_movements')->whereIn('variant_id', $variants)->exists()
                    || DB::table('stock_balances')->whereIn('variant_id', $variants)->where(fn ($q) => $q->where('on_hand', '!=', '0')->orWhere('reserved', '!=', '0'))->exists()
                    || DB::table('stock_reservations')->whereIn('variant_id', $variants)->exists()
                    || DB::table('order_items')->whereIn('variant_id', $variants)->exists()
                    || DB::table('invoice_items')->whereIn('variant_id', $variants)->exists()
                    || DB::table('product_images')->where('product_id', $product->id)->exists()
                    || DB::table('media_upload_sessions')->where('product_id', $product->id)->exists();
                abort_if($used, 409, 'El lote tiene productos usados en inventario, ventas o imágenes. No se revirtió ningún producto.');
            }
            foreach ($snapshots as $snapshot) {
                $product = $products[$snapshot['id']];
                $this->products->update($product->id, ['name' => $product->name, 'description' => $product->description, 'category_id' => $product->category_id, 'brand_id' => $product->brand_id, 'product_type' => $product->product_type, 'unit_code' => $product->unit_code, 'track_stock' => $product->track_stock, 'status' => 'archived', 'version' => $product->lock_version], $actor);
            }
            $batch->status = 'reverted';
            $batch->reverted_at = now();
            $batch->save();
            $this->audit->record('catalog.import.reverted', $batch, after: $this->summary($batch), actor: $actor);

            return $batch;
        }, 3);
    }

    private function fingerprint(Product $product): string
    {
        $state = $product->only(['name', 'description', 'category_id', 'brand_id', 'product_type', 'unit_code', 'track_stock', 'status', 'lock_version']);
        $state['variants'] = $product->variants()->withTrashed()->orderBy('id')->get()->map(function ($variant) {
            return [...$variant->only(['id', 'sku', 'barcode', 'sale_price', 'cost_price', 'combination_key', 'is_active', 'lock_version', 'deleted_at']), 'values' => DB::table('variant_attribute_values')->where('variant_id', $variant->id)->orderBy('attribute_id')->get(['attribute_id', 'value_id'])->toArray()];
        })->all();

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function errorCount(array $payload): int
    {
        return count(array_filter($payload['rows'], fn ($row) => $row['errors'] !== []));
    }

    private function hasCosts(array $payload): bool
    {
        foreach ($payload['groups'] as $group) {
            foreach ($group['data']['variants'] as $variant) {
                if (array_key_exists('cost_price', $variant)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function summary(CatalogImport $batch): array
    {
        return ['status' => $batch->status, 'row_count' => $batch->row_count, 'product_count' => $batch->product_count, 'error_count' => $batch->error_count];
    }

    public function present(CatalogImport $batch, User $user, int $page = 1): array
    {
        $actor = $this->actor($user, $batch);
        $rows = $batch->payload['rows'];
        $last = max(1, (int) ceil(count($rows) / 25));
        $page = min($page, $last);
        $rows = array_map(function ($row) use ($actor) {
            unset($row['source_errors']);
            if (! $actor->permits('catalog.costs')) {
                unset($row['cost_price']);
            }

            return $row;
        }, array_slice($rows, ($page - 1) * 25, 25));

        return ['id' => $batch->id, 'filename' => $batch->filename, ...$this->summary($batch), 'expires_at' => $batch->expires_at->toIso8601String(), 'is_expired' => $batch->status === 'preview' && $batch->expires_at->isPast(), 'can_confirm' => $batch->status === 'preview' && ! $batch->expires_at->isPast() && $batch->error_count === 0, 'can_revert' => $batch->status === 'committed', 'committed_at' => $batch->committed_at?->toIso8601String(), 'reverted_at' => $batch->reverted_at?->toIso8601String(), 'products' => array_map(fn ($product) => ['id' => $product['id'], 'name' => $product['name']], $batch->result['products'] ?? []), 'rows' => $rows, 'pagination' => ['current_page' => $page, 'last_page' => $last, 'total' => $batch->row_count, 'per_page' => 25]];
    }
}
