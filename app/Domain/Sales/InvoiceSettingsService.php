<?php

namespace App\Domain\Sales;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class InvoiceSettingsService
{
    public const DEFAULTS = [
        'logo_width_px' => 52,
        'accent_color' => '#4f46e5',
        'document_title' => 'FACTURA',
        'brand_subtitle' => null,
        'show_logo' => true,
        'show_product_images' => true,
        'show_sku' => true,
        'show_store_contact' => true,
        'show_customer_contact' => true,
        'show_payment_summary' => true,
        'show_payment_methods' => true,
        'show_notes' => true,
        'footer_text' => 'Gracias por su preferencia · Documento histórico de la operación.',
        'terms_text' => null,
    ];

    /**
     * @return array<string, mixed>
     */
    public function getForStore(int $storeId): array
    {
        $row = Schema::hasTable('store_invoice_settings')
            ? DB::table('store_invoice_settings')->where('store_id', $storeId)->first()
            : null;

        $logoPath = $row?->logo_path;
        $logoDataUri = $this->readLogoDataUri($logoPath);

        return [
            'store_id' => $storeId,
            'has_logo' => $logoDataUri !== null,
            'logo_data_uri' => $logoDataUri,
            'logo_width_px' => (int) ($row?->logo_width_px ?? self::DEFAULTS['logo_width_px']),
            'accent_color' => (string) ($row?->accent_color ?? self::DEFAULTS['accent_color']),
            'document_title' => (string) ($row?->document_title ?? self::DEFAULTS['document_title']),
            'brand_subtitle' => $row?->brand_subtitle ?? self::DEFAULTS['brand_subtitle'],
            'show_logo' => (bool) ($row?->show_logo ?? self::DEFAULTS['show_logo']),
            'show_product_images' => (bool) ($row?->show_product_images ?? self::DEFAULTS['show_product_images']),
            'show_sku' => (bool) ($row?->show_sku ?? self::DEFAULTS['show_sku']),
            'show_store_contact' => (bool) ($row?->show_store_contact ?? self::DEFAULTS['show_store_contact']),
            'show_customer_contact' => (bool) ($row?->show_customer_contact ?? self::DEFAULTS['show_customer_contact']),
            'show_payment_summary' => (bool) ($row?->show_payment_summary ?? self::DEFAULTS['show_payment_summary']),
            'show_payment_methods' => (bool) ($row?->show_payment_methods ?? self::DEFAULTS['show_payment_methods']),
            'show_notes' => (bool) ($row?->show_notes ?? self::DEFAULTS['show_notes']),
            'footer_text' => (string) ($row?->footer_text ?? self::DEFAULTS['footer_text']),
            'terms_text' => $row?->terms_text ?? self::DEFAULTS['terms_text'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateForStore(int $storeId, array $data): array
    {
        $now = now();
        $existing = DB::table('store_invoice_settings')->where('store_id', $storeId)->first();

        $payload = [
            'logo_width_px' => (int) ($data['logo_width_px'] ?? $existing?->logo_width_px ?? self::DEFAULTS['logo_width_px']),
            'accent_color' => (string) ($data['accent_color'] ?? $existing?->accent_color ?? self::DEFAULTS['accent_color']),
            'document_title' => trim((string) ($data['document_title'] ?? $existing?->document_title ?? self::DEFAULTS['document_title'])) ?: self::DEFAULTS['document_title'],
            'brand_subtitle' => isset($data['brand_subtitle']) ? (trim((string) $data['brand_subtitle']) ?: null) : ($existing?->brand_subtitle ?? null),
            'show_logo' => array_key_exists('show_logo', $data) ? (bool) $data['show_logo'] : (bool) ($existing?->show_logo ?? self::DEFAULTS['show_logo']),
            'show_product_images' => array_key_exists('show_product_images', $data) ? (bool) $data['show_product_images'] : (bool) ($existing?->show_product_images ?? self::DEFAULTS['show_product_images']),
            'show_sku' => array_key_exists('show_sku', $data) ? (bool) $data['show_sku'] : (bool) ($existing?->show_sku ?? self::DEFAULTS['show_sku']),
            'show_store_contact' => array_key_exists('show_store_contact', $data) ? (bool) $data['show_store_contact'] : (bool) ($existing?->show_store_contact ?? self::DEFAULTS['show_store_contact']),
            'show_customer_contact' => array_key_exists('show_customer_contact', $data) ? (bool) $data['show_customer_contact'] : (bool) ($existing?->show_customer_contact ?? self::DEFAULTS['show_customer_contact']),
            'show_payment_summary' => array_key_exists('show_payment_summary', $data) ? (bool) $data['show_payment_summary'] : (bool) ($existing?->show_payment_summary ?? self::DEFAULTS['show_payment_summary']),
            'show_payment_methods' => array_key_exists('show_payment_methods', $data) ? (bool) $data['show_payment_methods'] : (bool) ($existing?->show_payment_methods ?? self::DEFAULTS['show_payment_methods']),
            'show_notes' => array_key_exists('show_notes', $data) ? (bool) $data['show_notes'] : (bool) ($existing?->show_notes ?? self::DEFAULTS['show_notes']),
            'footer_text' => array_key_exists('footer_text', $data) ? (trim((string) $data['footer_text']) ?: self::DEFAULTS['footer_text']) : (string) ($existing?->footer_text ?? self::DEFAULTS['footer_text']),
            'terms_text' => array_key_exists('terms_text', $data) ? (trim((string) $data['terms_text']) ?: null) : ($existing?->terms_text ?? null),
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('store_invoice_settings')->where('store_id', $storeId)->update($payload);
        } else {
            DB::table('store_invoice_settings')->insert([
                'store_id' => $storeId,
                ...$payload,
                'created_at' => $now,
            ]);
        }

        return $this->getForStore($storeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function saveLogo(int $storeId, UploadedFile $file): array
    {
        $raw = @file_get_contents($file->getRealPath());
        if (! is_string($raw) || $raw === '') {
            throw ValidationException::withMessages(['logo' => 'No se pudo leer el archivo de imagen.']);
        }

        $pngBytes = $this->normalizeToPngBytes($raw, 360);
        if ($pngBytes === null) {
            throw ValidationException::withMessages(['logo' => 'Formato de imagen no válido. Usa PNG, JPG o WebP.']);
        }

        $path = "invoice-logos/{$storeId}/logo.png";
        Storage::disk('local')->put($path, $pngBytes);

        $existing = DB::table('store_invoice_settings')->where('store_id', $storeId)->first();
        if ($existing) {
            DB::table('store_invoice_settings')->where('store_id', $storeId)->update([
                'logo_path' => $path,
                'show_logo' => true,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('store_invoice_settings')->insert([
                'store_id' => $storeId,
                'logo_path' => $path,
                'show_logo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->getForStore($storeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeLogo(int $storeId): array
    {
        $existing = DB::table('store_invoice_settings')->where('store_id', $storeId)->first();
        if ($existing?->logo_path) {
            Storage::disk('local')->delete($existing->logo_path);
            DB::table('store_invoice_settings')->where('store_id', $storeId)->update([
                'logo_path' => null,
                'updated_at' => now(),
            ]);
        }

        return $this->getForStore($storeId);
    }

    /**
     * Resolves local PNG data URIs keyed by invoice_item id (or index fallback) for DomPDF.
     *
     * @param  iterable<int, object>  $items
     * @return array<int|string, string>
     */
    public function productImagesForInvoice(iterable $items, int $storeId): array
    {
        $variantIds = [];
        foreach ($items as $item) {
            if (! empty($item->variant_id)) {
                $variantIds[] = (int) $item->variant_id;
            }
        }
        $variantIds = array_values(array_unique($variantIds));
        if ($variantIds === []) {
            return [];
        }

        // Map variant_id -> product_id (scoped to store)
        $variantProducts = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->whereIn('pv.id', $variantIds)
            ->where('p.store_id', $storeId)
            ->pluck('p.id', 'pv.id')
            ->all();

        if ($variantProducts === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_map('intval', array_values($variantProducts))));

        $imageRows = DB::table('product_images as pi')
            ->join('media_assets as ma', 'ma.id', '=', 'pi.media_asset_id')
            ->join('media_renditions as mr', function ($join) {
                $join->on('mr.media_asset_id', '=', 'ma.id')
                    ->where('mr.rendition', '=', 'thumb');
            })
            ->whereIn('pi.product_id', $productIds)
            ->whereNull('pi.deleted_at')
            ->where('ma.status', 'ready')
            ->orderByDesc('pi.is_primary')
            ->orderBy('pi.sort_order')
            ->orderBy('pi.id')
            ->select('pi.product_id', 'pi.variant_id', 'mr.provider', 'mr.object_key')
            ->get();

        $byVariant = [];
        $byProduct = [];
        foreach ($imageRows as $row) {
            if ($row->variant_id && ! isset($byVariant[(int) $row->variant_id])) {
                $byVariant[(int) $row->variant_id] = $row;
            }
            if (! isset($byProduct[(int) $row->product_id])) {
                $byProduct[(int) $row->product_id] = $row;
            }
        }

        $cache = [];
        $result = [];
        foreach ($items as $item) {
            $vid = (int) ($item->variant_id ?? 0);
            if ($vid <= 0 || ! isset($variantProducts[$vid])) {
                continue;
            }
            $pid = (int) $variantProducts[$vid];
            $chosen = $byVariant[$vid] ?? $byProduct[$pid] ?? null;
            if (! $chosen) {
                continue;
            }
            $objectKey = (string) $chosen->object_key;
            if (! isset($cache[$objectKey])) {
                $cache[$objectKey] = $this->readRenditionAsPngDataUri((string) $chosen->provider, $objectKey, 96);
            }
            if ($cache[$objectKey] !== null) {
                $key = $item->id ?? $vid;
                $result[$key] = $cache[$objectKey];
            }
        }

        return $result;
    }

    public function sampleProductImageDataUri(int $storeId): ?string
    {
        $row = DB::table('product_images as pi')
            ->join('products as p', 'p.id', '=', 'pi.product_id')
            ->join('media_assets as ma', 'ma.id', '=', 'pi.media_asset_id')
            ->join('media_renditions as mr', function ($join) {
                $join->on('mr.media_asset_id', '=', 'ma.id')
                    ->where('mr.rendition', '=', 'thumb');
            })
            ->where('p.store_id', $storeId)
            ->whereNull('pi.deleted_at')
            ->where('ma.status', 'ready')
            ->orderByDesc('pi.is_primary')
            ->orderBy('pi.id')
            ->select('mr.provider', 'mr.object_key')
            ->first();

        if (! $row) {
            return null;
        }

        return $this->readRenditionAsPngDataUri((string) $row->provider, (string) $row->object_key, 96);
    }

    private function readLogoDataUri(?string $logoPath): ?string
    {
        if (! $logoPath) {
            return null;
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($logoPath)) {
            return null;
        }
        $bytes = $disk->get($logoPath);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function readRenditionAsPngDataUri(string $provider, string $objectKey, int $maxSize): ?string
    {
        if ($provider !== 'local') {
            return null;
        }
        $disk = Storage::disk('media_catalog');
        if (! $disk->exists($objectKey)) {
            return null;
        }
        $raw = $disk->get($objectKey);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $png = $this->normalizeToPngBytes($raw, $maxSize);

        return $png !== null ? 'data:image/png;base64,'.base64_encode($png) : null;
    }

    private function normalizeToPngBytes(string $raw, int $maxDim): ?string
    {
        $src = @imagecreatefromstring($raw);
        if (! $src && function_exists('imagecreatefromwebp')) {
            $tmp = @tempnam(sys_get_temp_dir(), 'ws_img_');
            if ($tmp !== false) {
                @file_put_contents($tmp, $raw);
                $src = @imagecreatefromwebp($tmp);
                @unlink($tmp);
            }
        }
        if (! $src) {
            return null;
        }

        try {
            $w = imagesx($src);
            $h = imagesy($src);
            if ($w <= 0 || $h <= 0) {
                return null;
            }
            $scale = min(1.0, $maxDim / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            ob_start();
            imagepng($dst, null, 6);
            $out = ob_get_clean();
            imagedestroy($dst);

            return is_string($out) && $out !== '' ? $out : null;
        } finally {
            imagedestroy($src);
        }
    }
}
