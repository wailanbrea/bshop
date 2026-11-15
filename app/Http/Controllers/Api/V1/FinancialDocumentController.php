<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\FinancialDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FinancialDocumentController extends Controller
{
    public function prepare(Request $request, StoreContext $context, FinancialDocument $documents)
    {
        $kind = $request->route('kind');
        $id = (int) $request->route('record');
        $order = (int) $request->route('order');
        $documents->authorizeSource($context, $order, $kind, $id);
        try {
            $document = $documents->ensure($kind, $id);
            $data = $documents->snapshot($document);
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getStatusCode() === 409 ? 'El comprobante se está preparando. Reintenta este mismo documento.' : 'El comprobante histórico requiere restauración o revisión.'], $e->getStatusCode())->header('Cache-Control', 'private, no-store');
        } catch (\Throwable) {
            return response()->json(['message' => 'La operación financiera está registrada. No se pudo preparar su comprobante; reintenta este documento.'], 503)->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => ['status' => 'ready', 'url' => '/api/v1/business/stores/'.$context->store()->id.'/sales/orders/'.$order.'/documents/'.$kind.'/'.$id, 'filename' => $data['reference'].'.pdf']])->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, StoreContext $context, FinancialDocument $documents)
    {
        $kind = $request->route('kind');
        $id = (int) $request->route('record');
        $documents->authorizeSource($context, (int) $request->route('order'), $kind, $id);
        $document = $documents->ready($kind, $id);
        $data = $documents->snapshot($document);

        return Storage::disk('private_invoices')->download($documents->key($kind, $id), $data['reference'].'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
