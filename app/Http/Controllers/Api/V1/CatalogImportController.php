<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\CatalogImportService;
use App\Domain\Catalog\CsvCatalogExport;
use App\Domain\Catalog\CsvCatalogReader;
use App\Http\Controllers\Controller;
use App\Models\CatalogImport;
use Illuminate\Http\Request;

class CatalogImportController extends Controller
{
    public function index(Request $request, CatalogImportService $service)
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $history = CatalogImport::where('created_by_user_id', $request->user()->id)->when(! $request->user()->permits('catalog.costs'), fn ($q) => $q->where('requires_costs', false))
            ->orderByDesc('id')->paginate(10, ['id', 'filename', 'status', 'row_count', 'product_count', 'error_count', 'created_at', 'expires_at']);

        return response()->json(['data' => $history->items(), 'meta' => ['current_page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'total' => $history->total()]])->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, CatalogImportService $service)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048']]);
        $batch = $service->preview($request->file('file'), $request->user());

        return response()->json(['data' => $service->present($batch, $request->user())], 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, CatalogImportService $service)
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(['data' => $service->present($service->owned($request->route('import'), $request->user()), $request->user(), (int) $request->input('page', 1))])->header('Cache-Control', 'no-store');
    }

    private function immutable(Request $request): void
    {
        abort_if($request->except('_token') !== [], 422, 'Los datos del lote no se pueden sustituir. Genera otra vista previa para cambiar el archivo.');
    }

    public function revalidate(Request $request, CatalogImportService $service)
    {
        $this->immutable($request);

        return response()->json(['data' => $service->present($service->revalidate($request->route('import'), $request->user()), $request->user())])->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request, CatalogImportService $service)
    {
        $this->immutable($request);
        $result = $service->confirm($request->route('import'), $request->user());

        return response()->json(['message' => $result['invalid'] ? 'El lote tiene errores. No se importó ningún producto.' : 'Importación confirmada. Los reintentos devuelven el mismo resultado.', 'data' => $service->present($result['batch'], $request->user())], $result['invalid'] ? 422 : 200)->header('Cache-Control', 'no-store');
    }

    public function revert(Request $request, CatalogImportService $service)
    {
        $this->immutable($request);

        return response()->json(['message' => 'Los productos del lote fueron archivados. El historial se conserva.', 'data' => $service->present($service->revert($request->route('import'), $request->user()), $request->user())])->header('Cache-Control', 'no-store');
    }

    public function template()
    {
        return response()->streamDownload(function () {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            CsvCatalogExport::write($stream, CsvCatalogReader::COLUMNS);
            CsvCatalogExport::write($stream, ['demo-jabon', 'Jabón de ejemplo', 'EJEMPLO-JABON', '125.50', '', '', 'physical', 'unit', '1', '', '', '', 'Producto de ejemplo; elimina estas filas antes de tu carga real.', 'active', '1']);
            CsvCatalogExport::write($stream, ['demo-servicio', 'Servicio de ejemplo', 'EJEMPLO-SERVICIO', '500.00', '', '', 'service', 'h', '0', '', '', '', 'Servicio de ejemplo.', 'active', '1']);
            fclose($stream);
        }, 'plantilla-catalogo-bshop.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function errors(Request $request, CatalogImportService $service)
    {
        $batch = $service->owned($request->route('import'), $request->user());

        return response()->streamDownload(function () use ($batch) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            CsvCatalogExport::write($stream, ['fila', 'grupo', 'sku', 'campo', 'error']);
            foreach ($batch->payload['rows'] as $row) {
                foreach ($row['errors'] as $error) {
                    CsvCatalogExport::write($stream, [$row['row'], $row['product_key'], $row['sku'], $error['field'], $error['message']]);
                }
            }
            fclose($stream);
        }, 'errores-importacion-'.$batch->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
