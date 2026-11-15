<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\WhatsAppInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WhatsAppInvoiceController extends Controller
{
    public function send(Request $request, StoreContext $context, WhatsAppInvoice $sender)
    {
        $data = $request->validate(['request_key' => ['required', 'uuid']]);

        return response()->json(['data' => $sender->send($context, (int) $request->route('invoice'), $data['request_key'], $request->user())]);
    }
}
