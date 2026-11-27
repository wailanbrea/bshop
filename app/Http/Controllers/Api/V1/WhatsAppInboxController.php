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

    public function updateCrm(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $data = $request->validate([
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'crm_stage' => ['sometimes', 'string', 'in:new,quoting,reserved,payment_pending,closed_won'],
            'tag_ids' => ['sometimes', 'array', 'max:20'],
            'tag_ids.*' => ['integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $inbox->updateConversationCrm($context->store()->id, (int) $request->route('conversation'), $data),
        ]);
    }

    public function addNote(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        return response()->json([
            'data' => $inbox->addInternalNote($context->store()->id, (int) $request->route('conversation'), $data['body'], $request->user()),
        ], 201);
    }

    public function createTag(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        return response()->json([
            'data' => $inbox->createTag($context->store()->id, $data['name'], $data['color'] ?? '#6d28d9'),
        ], 201);
    }

    public function deleteTag(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $inbox->deleteTag($context->store()->id, (int) $request->route('tag'));

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function upsertQuickReply(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $data = $request->validate([
            'shortcut' => ['required', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $inbox->upsertQuickReply($context->store()->id, $data['shortcut'], $data['title'] ?? '', $data['body'], $request->user()),
        ], 201);
    }

    public function deleteQuickReply(Request $request, StoreContext $context, WhatsAppInboxService $inbox)
    {
        $inbox->deleteQuickReply($context->store()->id, (int) $request->route('quickReply'));

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function pendingRequests(Request $request, StoreContext $context, \App\Domain\Sales\WhatsAppAutoApartadoService $autoApartado)
    {
        $convId = $request->filled('conversation_id') ? $request->integer('conversation_id') : null;

        return response()->json([
            'data' => $autoApartado->listPendingRequests($context->store()->id, $convId),
        ]);
    }

    public function confirmRequest(Request $request, StoreContext $context, \App\Domain\Sales\WhatsAppAutoApartadoService $autoApartado)
    {
        $data = $request->validate([
            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'mark_trusted' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $autoApartado->confirmPendingRequest(
                $context,
                (int) $request->route('reservationRequest'),
                $request->user(),
                isset($data['deposit_amount']) ? (string) $data['deposit_amount'] : null,
                (string) ($data['payment_method'] ?? 'zelle'),
                (bool) ($data['mark_trusted'] ?? false),
            ),
        ]);
    }

    public function cancelRequest(Request $request, StoreContext $context, \App\Domain\Sales\WhatsAppAutoApartadoService $autoApartado)
    {
        $autoApartado->cancelPendingRequest($context->store()->id, (int) $request->route('reservationRequest'));

        return response()->json(['data' => ['cancelled' => true]]);
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
