<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\WhatsAppInboxService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WhatsAppInboxController extends Controller
{
    public function unreadSummary(StoreContext $context, WhatsAppInboxService $inbox)
    {
        return response()->json([
            'data' => $inbox->unreadSummary($context->store()->id),
        ]);
    }

    public function index(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $search = (string) $request->query('q', '');

        return response()->json([
            'data' => $inbox->listConversations($context->store()->id, $search),
        ]);
    }

    public function show(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        return response()->json([
            'data' => $inbox->getThread($context->store()->id, (int) $request->route('conversation')),
        ]);
    }

    public function send(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4096'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);

        $msg = $inbox->sendReply(
            $context->store()->id,
            (int) $request->route('conversation'),
            $data['body'] ?? null,
            $request->file('attachment'),
            $request->user(),
        );

        return response()->json(['data' => $msg], 201);
    }

    public function destroy(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $inbox->deleteConversation($context->store()->id, (int) $request->route('conversation'));

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function media(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $file = $inbox->fetchMessageMedia($context->store()->id, (int) $request->route('message'));
        $isInline = str_starts_with($file['mime'], 'image/') || $file['mime'] === 'application/pdf' || str_starts_with($file['mime'], 'audio/');

        return response($file['bytes'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => ($isInline ? 'inline' : 'attachment').'; filename="'.$file['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
