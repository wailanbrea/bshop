<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Store } from '../types';

type CrmTag = {
    id: number;
    name: string;
    color: string;
};

type CrmStage = {
    key: 'new' | 'quoting' | 'reserved' | 'payment_pending' | 'closed_won';
    label: string;
    color: string;
};

type CrmAgent = {
    id: number;
    name: string;
};

type QuickReply = {
    id: number;
    shortcut: string;
    title: string;
    body: string;
};

type ConversationSummary = {
    id: number;
    customer_id: number | null;
    display_name: string;
    customer_name: string | null;
    contact_name: string | null;
    wa_phone: string;
    unread_count: number;
    window_open: boolean;
    window_expires_at: string | null;
    last_message_at: string | null;
    last_message_preview: string | null;
    last_message_direction: 'inbound' | 'outbound';
    assigned_user_id: number | null;
    assigned_user_name: string | null;
    crm_stage: CrmStage['key'];
    tags: CrmTag[];
    open_orders_total: string | null;
    open_orders_count: number;
};

type ChatMessage = {
    id: number;
    direction: 'inbound' | 'outbound';
    is_internal?: boolean;
    message_type: 'text' | 'image' | 'document' | 'audio' | 'template' | 'other';
    body: string | null;
    has_media: boolean;
    media_mime: string | null;
    media_filename: string | null;
    template_name: string | null;
    status: string;
    error_code: string | null;
    sender_user_name: string | null;
    occurred_at: string;
};

type CustomerContext = {
    id: number;
    full_name: string;
    recent_orders: Array<{
        id: number;
        label: string;
        status: string;
        grand_total: string;
        currency: string;
    }>;
};

type PendingDepositRequest = {
    id: number;
    conversation_id: number | null;
    customer_id: number;
    customer_name: string;
    customer_phone: string | null;
    product_name: string;
    variant_sku: string;
    variant_label: string;
    quantity: string;
    unit_price: string;
    required_deposit: string;
    available_stock: string | null;
    in_stock: boolean;
    requested_text: string | null;
    created_at: string | null;
};

const props = defineProps<{
    store?: Store;
    permissions: string[];
    initialConversationId?: number | null;
}>();

const emit = defineEmits<{
    'open-order': [number];
    'open-settings': [];
    'read-updated': [];
}>();

const viewMode = ref<'inbox' | 'kanban'>('inbox');
const loadingList = ref(false);
const loadingThread = ref(false);
const sending = ref(false);
const deletingChat = ref(false);
const confirmDeleteChat = ref(false);
const savingCrm = ref(false);
const error = ref('');
const sendError = ref('');
const search = ref('');
const filterMode = ref<'all' | 'unread' | 'read' | 'window'>('all');
const filterTagId = ref<number | ''>('');
const filterAgentId = ref<number | '' | 'unassigned'>('');

const whatsappReady = ref(false);
const needsAppSecret = ref(false);
const conversations = ref<ConversationSummary[]>([]);
const crmStages = ref<CrmStage[]>([
    { key: 'new', label: 'Nuevo lead', color: '#6366f1' },
    { key: 'quoting', label: 'Cotizando', color: '#0ea5e9' },
    { key: 'reserved', label: 'Apartado', color: '#8b5cf6' },
    { key: 'payment_pending', label: 'Por pagar', color: '#f59e0b' },
    { key: 'closed_won', label: 'Cerrado', color: '#16a34a' },
]);
const storeTags = ref<CrmTag[]>([]);
const agents = ref<CrmAgent[]>([]);
const quickReplies = ref<QuickReply[]>([]);

// Managers (Tags & Quick Replies)
const showTagManager = ref(false);
const newTagName = ref('');
const newTagColor = ref('#6d28d9');
const showQuickReplyManager = ref(false);
const qrShortcut = ref('');
const qrTitle = ref('');
const qrBody = ref('');
const qrPopoverOpen = ref(false);

const tagColors = ['#6d28d9', '#d97706', '#2563eb', '#16a34a', '#dc2626', '#db2777', '#0891b2'];

const selectedId = ref<number | null>(props.initialConversationId || null);
const activeConversation = ref<any>(null);
const customerContext = ref<CustomerContext | null>(null);
const messages = ref<ChatMessage[]>([]);
const pendingRequests = ref<PendingDepositRequest[]>([]);
const reqBusyId = ref<number | null>(null);
const reqDepositById = ref<Record<number, string>>({});
const reqTrustById = ref<Record<number, boolean>>({});
const reqNotice = ref('');
const composerMode = ref<'whatsapp' | 'note'>('whatsapp');
const draftText = ref('');
const attachment = ref<File | null>(null);
const fileInput = ref<HTMLInputElement>();
const threadEl = ref<HTMLElement>();
const draggingConvId = ref<number | null>(null);
const dragOverStage = ref<string | null>(null);
const notifPermission = ref<string>(typeof Notification !== 'undefined' ? Notification.permission : 'unsupported');

const canSend = computed(() => props.permissions.includes('sales.create'));
const root = computed(() => (props.store?.id ? `/business/stores/${props.store.id}/whatsapp/inbox` : ''));

const filteredConversations = computed(() => {
    return conversations.value.filter((c) => {
        if (filterMode.value === 'unread' && c.unread_count === 0) return false;
        if (filterMode.value === 'read' && c.unread_count > 0) return false;
        if (filterMode.value === 'window' && !c.window_open) return false;
        if (filterTagId.value !== '' && !c.tags.some((t) => t.id === filterTagId.value)) return false;
        if (filterAgentId.value === 'unassigned' && c.assigned_user_id !== null) return false;
        if (typeof filterAgentId.value === 'number' && c.assigned_user_id !== filterAgentId.value) return false;
        return true;
    });
});

const filterCounts = computed(() => ({
    all: conversations.value.length,
    unread: conversations.value.filter((c) => c.unread_count > 0).length,
    read: conversations.value.filter((c) => c.unread_count === 0).length,
    window: conversations.value.filter((c) => c.window_open).length,
}));

const slashSuggestions = computed(() => {
    if (composerMode.value !== 'whatsapp') return [];
    const txt = draftText.value;
    if (qrPopoverOpen.value) return quickReplies.value;
    if (!txt.startsWith('/')) return [];
    const term = txt.slice(1).trim().toLowerCase();
    return quickReplies.value.filter(
        (q) => !term || q.shortcut.toLowerCase().includes(term) || q.title.toLowerCase().includes(term),
    );
});

function stageMeta(key: string): CrmStage {
    return crmStages.value.find((s) => s.key === key) || crmStages.value[0];
}

function conversationsInStage(stageKey: string): ConversationSummary[] {
    return filteredConversations.value.filter((c) => (c.crm_stage || 'new') === stageKey);
}

function stageTotalAmount(stageKey: string): string {
    const sum = conversationsInStage(stageKey).reduce((acc, c) => acc + Number(c.open_orders_total || 0), 0);
    return sum > 0 ? `US$ ${sum.toFixed(2)}` : '';
}

function mediaUrl(messageId: number): string {
    return `/api/v1${root.value}/messages/${messageId}/media`;
}

function formatTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    return sameDay
        ? d.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString('es', { day: '2-digit', month: 'short' });
}

function formatDateTime(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('es', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function statusTicks(m: ChatMessage): string {
    if (m.is_internal) return 'Solo equipo';
    if (m.direction !== 'outbound') return '';
    if (m.status === 'read') return '✓✓ Leído';
    if (m.status === 'delivered') return '✓✓ Entregado';
    if (m.status === 'sent') return '✓ Enviado';
    if (m.status === 'failed') return '⚠ Falló';
    return '…';
}

function orderStatusLabel(status: string): string {
    if (status === 'invoiced') return 'Facturado';
    if (status === 'reserved' || status === 'draft') return 'Abierto';
    if (status === 'confirmed') return 'Confirmado';
    return status;
}

async function scrollToBottom() {
    await nextTick();
    if (threadEl.value) {
        threadEl.value.scrollTop = threadEl.value.scrollHeight;
    }
}

async function loadConversations(silent = false) {
    if (!root.value) return;
    if (!silent) loadingList.value = true;
    error.value = '';
    try {
        const res = (
            await api.get(`${root.value}/conversations`, {
                params: search.value.trim() ? { q: search.value.trim() } : undefined,
            })
        ).data.data;
        whatsappReady.value = !!res.whatsapp_ready;
        needsAppSecret.value = !!res.needs_app_secret;
        conversations.value = res.conversations || [];
        if (Array.isArray(res.crm_stages) && res.crm_stages.length) crmStages.value = res.crm_stages;
        if (Array.isArray(res.tags)) storeTags.value = res.tags;
        if (Array.isArray(res.agents)) agents.value = res.agents;
        if (Array.isArray(res.quick_replies)) quickReplies.value = res.quick_replies;
    } catch (e) {
        if (!silent) error.value = errorMessage(e);
    } finally {
        if (!silent) loadingList.value = false;
    }
}

async function openConversation(id: number, silent = false) {
    if (!root.value) return;
    if (!silent) {
        confirmDeleteChat.value = false;
        qrPopoverOpen.value = false;
        viewMode.value = 'inbox';
    }
    selectedId.value = id;
    sendError.value = '';
    if (!silent) loadingThread.value = true;
    try {
        const prevLastId = messages.value.at(-1)?.id || 0;
        const res = (await api.get(`${root.value}/conversations/${id}`)).data.data;
        activeConversation.value = res.conversation;
        customerContext.value = res.customer_context;
        messages.value = res.messages || [];
        const local = conversations.value.find((c) => c.id === id);
        if (local && local.unread_count > 0) {
            local.unread_count = 0;
        }
        emit('read-updated');
        await loadPendingRequests(id);
        const newLastId = messages.value.at(-1)?.id || 0;
        if (!silent || newLastId !== prevLastId) {
            await scrollToBottom();
        }
    } catch (e) {
        if (!silent) error.value = errorMessage(e);
    } finally {
        if (!silent) loadingThread.value = false;
    }
}

async function loadPendingRequests(conversationId: number) {
    if (!root.value) return;
    try {
        const rows: PendingDepositRequest[] = (
            await api.get(`${root.value}/reservation-requests`, { params: { conversation_id: conversationId } })
        ).data.data || [];
        pendingRequests.value = rows;
        for (const r of rows) {
            if (reqDepositById.value[r.id] === undefined) {
                reqDepositById.value[r.id] = r.required_deposit;
            }
        }
    } catch {
        // Non-blocking
    }
}

async function confirmDepositRequest(req: PendingDepositRequest) {
    if (!root.value || reqBusyId.value !== null || !canSend.value) return;
    reqBusyId.value = req.id;
    error.value = '';
    reqNotice.value = '';
    try {
        const rawAmt = (reqDepositById.value[req.id] ?? '').trim();
        const res = (
            await api.post(`${root.value}/reservation-requests/${req.id}/confirm`, {
                deposit_amount: rawAmt !== '' ? rawAmt : null,
                payment_method: 'zelle',
                mark_trusted: Boolean(reqTrustById.value[req.id]),
            })
        ).data.data;
        pendingRequests.value = pendingRequests.value.filter((x) => x.id !== req.id);
        reqNotice.value = `✓ Depósito confirmado: se creó el Apartado #${res.order_id} para ${req.customer_name}.`;
        if (selectedId.value) {
            await openConversation(selectedId.value, true);
        }
        await loadConversations(true);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        reqBusyId.value = null;
    }
}

async function cancelDepositRequest(req: PendingDepositRequest) {
    if (!root.value || reqBusyId.value !== null || !canSend.value) return;
    reqBusyId.value = req.id;
    error.value = '';
    reqNotice.value = '';
    try {
        await api.post(`${root.value}/reservation-requests/${req.id}/cancel`);
        pendingRequests.value = pendingRequests.value.filter((x) => x.id !== req.id);
        reqNotice.value = `Solicitud de ${req.product_name} descartada (el stock nunca se bloqueó).`;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        reqBusyId.value = null;
    }
}

async function updateCrm(conversationId: number, patch: { assigned_user_id?: number | null; crm_stage?: CrmStage['key']; tag_ids?: number[] }) {
    if (!root.value || !canSend.value) return;
    savingCrm.value = true;
    error.value = '';
    try {
        const updated = (await api.patch(`${root.value}/conversations/${conversationId}/crm`, patch)).data.data;
        const idx = conversations.value.findIndex((c) => c.id === conversationId);
        if (idx >= 0) {
            conversations.value[idx] = {
                ...conversations.value[idx],
                assigned_user_id: updated.assigned_user_id,
                assigned_user_name: updated.assigned_user_name,
                crm_stage: updated.crm_stage,
                tags: updated.tags,
            };
        }
        if (activeConversation.value?.id === conversationId) {
            activeConversation.value = {
                ...activeConversation.value,
                assigned_user_id: updated.assigned_user_id,
                assigned_user_name: updated.assigned_user_name,
                crm_stage: updated.crm_stage,
                tags: updated.tags,
            };
        }
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        savingCrm.value = false;
    }
}

function toggleConversationTag(tagId: number) {
    if (!activeConversation.value || !canSend.value) return;
    const currentIds: number[] = (activeConversation.value.tags || []).map((t: CrmTag) => t.id);
    const nextIds = currentIds.includes(tagId) ? currentIds.filter((id) => id !== tagId) : [...currentIds, tagId];
    updateCrm(activeConversation.value.id, { tag_ids: nextIds });
}

function onAgentSelect(ev: Event) {
    if (!activeConversation.value) return;
    const val = (ev.target as HTMLSelectElement).value;
    updateCrm(activeConversation.value.id, { assigned_user_id: val ? Number(val) : null });
}

function onStageSelect(convId: number, ev: Event) {
    const val = (ev.target as HTMLSelectElement).value as CrmStage['key'];
    updateCrm(convId, { crm_stage: val });
}

// Kanban Drag & Drop
function onDragStart(convId: number) {
    draggingConvId.value = convId;
}
function onDragOver(stageKey: string) {
    dragOverStage.value = stageKey;
}
function onDropStage(stageKey: CrmStage['key']) {
    const id = draggingConvId.value;
    draggingConvId.value = null;
    dragOverStage.value = null;
    if (!id) return;
    const conv = conversations.value.find((c) => c.id === id);
    if (conv && conv.crm_stage !== stageKey) {
        updateCrm(id, { crm_stage: stageKey });
    }
}

async function createStoreTag() {
    if (!root.value || !newTagName.value.trim()) return;
    try {
        const tag: CrmTag = (await api.post(`${root.value}/tags`, { name: newTagName.value.trim(), color: newTagColor.value })).data.data;
        if (!storeTags.value.some((t) => t.id === tag.id)) {
            storeTags.value.push(tag);
        }
        newTagName.value = '';
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function removeStoreTag(tagId: number) {
    if (!root.value) return;
    try {
        await api.delete(`${root.value}/tags/${tagId}`);
        storeTags.value = storeTags.value.filter((t) => t.id !== tagId);
        conversations.value.forEach((c) => {
            c.tags = c.tags.filter((t) => t.id !== tagId);
        });
        if (activeConversation.value) {
            activeConversation.value.tags = (activeConversation.value.tags || []).filter((t: CrmTag) => t.id !== tagId);
        }
        if (filterTagId.value === tagId) filterTagId.value = '';
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function saveQuickReply() {
    if (!root.value || !qrShortcut.value.trim() || !qrBody.value.trim()) return;
    try {
        const saved: QuickReply = (
            await api.post(`${root.value}/quick-replies`, {
                shortcut: qrShortcut.value.trim(),
                title: qrTitle.value.trim(),
                body: qrBody.value.trim(),
            })
        ).data.data;
        const idx = quickReplies.value.findIndex((q) => q.id === saved.id || q.shortcut === saved.shortcut);
        if (idx >= 0) quickReplies.value[idx] = saved;
        else quickReplies.value.push(saved);
        qrShortcut.value = '';
        qrTitle.value = '';
        qrBody.value = '';
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function removeQuickReply(id: number) {
    if (!root.value) return;
    try {
        await api.delete(`${root.value}/quick-replies/${id}`);
        quickReplies.value = quickReplies.value.filter((q) => q.id !== id);
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function applyQuickReply(q: QuickReply) {
    draftText.value = q.body;
    qrPopoverOpen.value = false;
}

async function deleteActiveConversation() {
    if (!root.value || !selectedId.value || deletingChat.value || !canSend.value) return;
    deletingChat.value = true;
    error.value = '';
    const targetId = selectedId.value;
    try {
        await api.delete(`${root.value}/conversations/${targetId}`);
        conversations.value = conversations.value.filter((c) => c.id !== targetId);
        selectedId.value = null;
        activeConversation.value = null;
        customerContext.value = null;
        messages.value = [];
        confirmDeleteChat.value = false;
        emit('read-updated');
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        deletingChat.value = false;
    }
}

function onPickFile(ev: Event) {
    const target = ev.target as HTMLInputElement;
    attachment.value = target.files?.[0] || null;
}

function clearAttachment() {
    attachment.value = null;
    if (fileInput.value) fileInput.value.value = '';
}

async function sendMessage() {
    if (!root.value || !selectedId.value || sending.value || !canSend.value) return;
    if (composerMode.value === 'note') {
        if (!draftText.value.trim()) return;
        sending.value = true;
        sendError.value = '';
        try {
            const note: ChatMessage = (
                await api.post(`${root.value}/conversations/${selectedId.value}/notes`, { body: draftText.value.trim() })
            ).data.data;
            messages.value.push(note);
            draftText.value = '';
            await scrollToBottom();
        } catch (e) {
            sendError.value = errorMessage(e);
        } finally {
            sending.value = false;
        }
        return;
    }

    if (!draftText.value.trim() && !attachment.value) return;
    sending.value = true;
    sendError.value = '';
    try {
        const fd = new FormData();
        if (draftText.value.trim()) fd.append('body', draftText.value.trim());
        if (attachment.value) fd.append('attachment', attachment.value);

        const created: ChatMessage = (
            await api.post(`${root.value}/conversations/${selectedId.value}/messages`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            })
        ).data.data;

        messages.value.push(created);
        draftText.value = '';
        qrPopoverOpen.value = false;
        clearAttachment();
        await scrollToBottom();
        await loadConversations(true);
    } catch (e) {
        sendError.value = errorMessage(e);
    } finally {
        sending.value = false;
    }
}

function onComposerKeydown(ev: KeyboardEvent) {
    if (ev.key === 'Enter' && !ev.shiftKey) {
        if (slashSuggestions.value.length === 1 && draftText.value.startsWith('/')) {
            ev.preventDefault();
            applyQuickReply(slashSuggestions.value[0]);
            return;
        }
        ev.preventDefault();
        sendMessage();
    } else if (ev.key === 'Escape') {
        qrPopoverOpen.value = false;
    }
}

async function requestBrowserNotifications() {
    if (typeof Notification === 'undefined') return;
    try {
        notifPermission.value = await Notification.requestPermission();
    } catch {
        /* Browser denied or unsupported */
    }
}

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(search, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadConversations(), 260);
});

watch(
    () => props.initialConversationId,
    (newId) => {
        if (newId && newId !== selectedId.value) {
            openConversation(newId);
        }
    },
);

let pollTimer: ReturnType<typeof setInterval> | null = null;
onMounted(async () => {
    await loadConversations();
    if (props.initialConversationId) {
        await openConversation(props.initialConversationId);
    } else if (conversations.value.length && window.innerWidth >= 900) {
        await openConversation(conversations.value[0].id);
    }
    pollTimer = setInterval(async () => {
        if (document.hidden) return;
        await loadConversations(true);
        if (selectedId.value && viewMode.value === 'inbox') {
            await openConversation(selectedId.value, true);
        }
    }, 8000);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
    if (searchTimer) clearTimeout(searchTimer);
});

defineExpose({ openConversation, reload: loadConversations });
</script>

<template>
    <section class="wa-inbox-shell" :class="{ 'mobile-thread-open': !!selectedId && viewMode === 'inbox' }">
        <!-- Barra superior CRM: Bandeja / Kanban + Etiquetas + Respuestas rápidas -->
        <div class="wa-crm-topbar">
            <div class="wa-view-tabs" role="tablist" aria-label="Vista del CRM de WhatsApp">
                <button
                    type="button"
                    role="tab"
                    class="wa-view-tab"
                    :class="{ active: viewMode === 'inbox' }"
                    :aria-selected="viewMode === 'inbox'"
                    @click="viewMode = 'inbox'"
                >
                    💬 Bandeja de chats
                </button>
                <button
                    type="button"
                    role="tab"
                    class="wa-view-tab"
                    :class="{ active: viewMode === 'kanban' }"
                    :aria-selected="viewMode === 'kanban'"
                    @click="viewMode = 'kanban'"
                >
                    📊 Embudo Kanban
                </button>
            </div>
            <div v-if="canSend" class="wa-crm-tools">
                <button
                    type="button"
                    class="button secondary wa-tool-btn"
                    :class="{ active: showTagManager }"
                    @click="showTagManager = !showTagManager; showQuickReplyManager = false"
                >
                    🏷️ Etiquetas ({{ storeTags.length }})
                </button>
                <button
                    type="button"
                    class="button secondary wa-tool-btn"
                    :class="{ active: showQuickReplyManager }"
                    @click="showQuickReplyManager = !showQuickReplyManager; showTagManager = false"
                >
                    ⚡ Respuestas rápidas ({{ quickReplies.length }})
                </button>
            </div>
        </div>

        <!-- Panel desplegable: Gestor de Etiquetas -->
        <section v-if="showTagManager && canSend" class="panel wa-manager-panel" aria-label="Gestionar etiquetas CRM">
            <div class="wa-manager-head">
                <strong>Etiquetas de la tienda</strong>
                <button type="button" class="text-button" @click="showTagManager = false">Cerrar ×</button>
            </div>
            <div class="wa-manager-tags-list">
                <span
                    v-for="tag in storeTags"
                    :key="tag.id"
                    class="wa-tag-chip"
                    :style="{ background: tag.color + '1a', color: tag.color, borderColor: tag.color + '55' }"
                >
                    {{ tag.name }}
                    <button type="button" class="wa-tag-remove" :aria-label="`Eliminar ${tag.name}`" @click="removeStoreTag(tag.id)">×</button>
                </span>
            </div>
            <form class="wa-manager-form" @submit.prevent="createStoreTag">
                <input v-model="newTagName" type="text" maxlength="40" placeholder="Nueva etiqueta (ej. Urgente, Mayorista)…" required />
                <div class="wa-color-swatches">
                    <button
                        v-for="col in tagColors"
                        :key="col"
                        type="button"
                        class="wa-color-dot"
                        :class="{ active: newTagColor === col }"
                        :style="{ background: col }"
                        :aria-label="`Color ${col}`"
                        @click="newTagColor = col"
                    ></button>
                </div>
                <button type="submit" class="button primary wa-tool-btn">Crear etiqueta</button>
            </form>
        </section>

        <!-- Panel desplegable: Gestor de Respuestas rápidas (/atajo) -->
        <section v-if="showQuickReplyManager && canSend" class="panel wa-manager-panel" aria-label="Gestionar respuestas rápidas">
            <div class="wa-manager-head">
                <strong>Respuestas rápidas (escribe <code>/</code> en el chat para usarlas)</strong>
                <button type="button" class="text-button" @click="showQuickReplyManager = false">Cerrar ×</button>
            </div>
            <div class="wa-qr-list">
                <div v-for="q in quickReplies" :key="q.id" class="wa-qr-row">
                    <div class="wa-qr-info">
                        <code>/{{ q.shortcut }}</code>
                        <strong>{{ q.title }}</strong>
                        <p class="muted">{{ q.body }}</p>
                    </div>
                    <button type="button" class="button secondary wa-delete-chat-btn" @click="removeQuickReply(q.id)">Quitar</button>
                </div>
            </div>
            <form class="wa-qr-form" @submit.prevent="saveQuickReply">
                <input v-model="qrShortcut" type="text" maxlength="40" placeholder="Atajo sin barra (ej. envio, zelle)" required />
                <input v-model="qrTitle" type="text" maxlength="80" placeholder="Título corto (ej. Datos de Zelle)" />
                <textarea v-model="qrBody" rows="2" maxlength="2000" placeholder="Texto completo que se insertará en el chat…" required></textarea>
                <button type="submit" class="button primary wa-tool-btn">Guardar respuesta rápida</button>
            </form>
        </section>

        <!-- Avisos superiores de estado -->
        <div v-if="needsAppSecret || notifPermission === 'default'" class="wa-inbox-banners">
            <div v-if="needsAppSecret" class="notice warning wa-banner-row" role="status">
                <div>
                    <strong>Falta el App Secret para recibir mensajes entrantes:</strong>
                    pega la <em>Clave secreta de la app</em> de Meta en Configuración → WhatsApp para que los mensajes de tus clientas lleguen aquí verificados.
                </div>
                <button type="button" class="button secondary" @click="emit('open-settings')">Configurar App Secret</button>
            </div>
            <div v-if="notifPermission === 'default'" class="notice wa-banner-row">
                <span>Activa las alertas del navegador/PWA para escuchar y ver avisos cuando escriba una clienta.</span>
                <button type="button" class="button secondary" @click="requestBrowserNotifications">Activar alertas</button>
            </div>
        </div>

        <p v-if="error" class="notice error" role="alert">{{ error }}</p>

        <!-- VISTA 1: EMBUDO KANBAN -->
        <section v-if="viewMode === 'kanban'" class="wa-kanban-shell" aria-label="Embudo Kanban de oportunidades">
            <div class="wa-kanban-toolbar">
                <input v-model="search" type="search" placeholder="Filtrar leads por nombre o teléfono…" class="wa-kanban-search" />
                <select v-model="filterTagId" aria-label="Filtrar por etiqueta" class="wa-crm-select">
                    <option value="">Todas las etiquetas</option>
                    <option v-for="t in storeTags" :key="t.id" :value="t.id">🏷️ {{ t.name }}</option>
                </select>
                <select v-model="filterAgentId" aria-label="Filtrar por vendedor" class="wa-crm-select">
                    <option value="">Todos los vendedores</option>
                    <option value="unassigned">Sin asignar</option>
                    <option v-for="a in agents" :key="a.id" :value="a.id">👤 {{ a.name }}</option>
                </select>
                <button type="button" class="button secondary wa-tool-btn" :disabled="loadingList" @click="loadConversations()">Actualizar</button>
            </div>

            <div class="wa-kanban-board">
                <div
                    v-for="stage in crmStages"
                    :key="stage.key"
                    class="wa-kanban-col"
                    :class="{ 'drag-over': dragOverStage === stage.key }"
                    @dragover.prevent="onDragOver(stage.key)"
                    @dragleave="dragOverStage = null"
                    @drop.prevent="onDropStage(stage.key)"
                >
                    <header class="wa-kanban-col-head" :style="{ borderTopColor: stage.color }">
                        <div class="wa-kanban-col-title">
                            <strong>{{ stage.label }}</strong>
                            <span class="wa-filter-count">{{ conversationsInStage(stage.key).length }}</span>
                        </div>
                        <span v-if="stageTotalAmount(stage.key)" class="wa-kanban-col-sum">{{ stageTotalAmount(stage.key) }}</span>
                    </header>

                    <div class="wa-kanban-cards">
                        <p v-if="!conversationsInStage(stage.key).length" class="muted wa-kanban-empty">Sin chats aquí</p>
                        <article
                            v-for="conv in conversationsInStage(stage.key)"
                            :key="conv.id"
                            class="wa-kanban-card"
                            :draggable="canSend"
                            @dragstart="onDragStart(conv.id)"
                        >
                            <div class="wa-kanban-card-top">
                                <strong>{{ conv.display_name }}</strong>
                                <span v-if="conv.unread_count > 0" class="wa-unread-pill">{{ conv.unread_count }}</span>
                            </div>
                            <small class="muted">{{ conv.wa_phone }}</small>
                            <p class="wa-kanban-preview">{{ conv.last_message_preview || 'Sin mensajes' }}</p>

                            <div v-if="conv.tags.length" class="wa-card-tags">
                                <span
                                    v-for="t in conv.tags"
                                    :key="t.id"
                                    class="wa-tag-mini"
                                    :style="{ background: t.color + '1a', color: t.color }"
                                >
                                    {{ t.name }}
                                </span>
                            </div>

                            <div class="wa-kanban-meta">
                                <span v-if="conv.open_orders_total" class="wa-kanban-money">US$ {{ conv.open_orders_total }}</span>
                                <span v-if="conv.assigned_user_name" class="wa-agent-pill">👤 {{ conv.assigned_user_name }}</span>
                            </div>

                            <div class="wa-kanban-card-actions">
                                <select
                                    v-if="canSend"
                                    class="wa-stage-mini-select"
                                    :value="conv.crm_stage"
                                    aria-label="Cambiar etapa"
                                    @change="onStageSelect(conv.id, $event)"
                                >
                                    <option v-for="st in crmStages" :key="st.key" :value="st.key">{{ st.label }}</option>
                                </select>
                                <button type="button" class="button secondary wa-open-chat-btn" @click="openConversation(conv.id)">
                                    Abrir chat →
                                </button>
                            </div>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <!-- VISTA 2: BANDEJA DE CHATS -->
        <div v-else class="wa-inbox-grid">
            <!-- Columna izquierda: Conversaciones -->
            <aside class="panel wa-conv-list-panel" aria-label="Conversaciones de WhatsApp">
                <div class="wa-conv-header">
                    <div>
                        <h2>Conversaciones</h2>
                        <small class="muted">{{ store?.name || 'Sucursal activa' }}</small>
                    </div>
                    <button type="button" class="button secondary wa-refresh-btn" :disabled="loadingList" @click="loadConversations()">
                        Actualizar
                    </button>
                </div>

                <div class="wa-conv-search">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Buscar clienta, teléfono o mensaje…"
                        aria-label="Buscar conversación"
                    />
                    <div class="wa-filter-pills" role="tablist" aria-label="Filtrar conversaciones">
                        <button type="button" role="tab" class="wa-filter-pill" :class="{ active: filterMode === 'all' }" :aria-selected="filterMode === 'all'" @click="filterMode = 'all'">
                            Todos <span class="wa-filter-count">{{ filterCounts.all }}</span>
                        </button>
                        <button type="button" role="tab" class="wa-filter-pill" :class="{ active: filterMode === 'unread' }" :aria-selected="filterMode === 'unread'" @click="filterMode = 'unread'">
                            No leídos <span class="wa-filter-count" :class="{ highlight: filterCounts.unread > 0 }">{{ filterCounts.unread }}</span>
                        </button>
                        <button type="button" role="tab" class="wa-filter-pill" :class="{ active: filterMode === 'read' }" :aria-selected="filterMode === 'read'" @click="filterMode = 'read'">
                            Leídos <span class="wa-filter-count">{{ filterCounts.read }}</span>
                        </button>
                        <button type="button" role="tab" class="wa-filter-pill" :class="{ active: filterMode === 'window' }" :aria-selected="filterMode === 'window'" @click="filterMode = 'window'">
                            24h gratis <span class="wa-filter-count">{{ filterCounts.window }}</span>
                        </button>
                    </div>
                    <div class="wa-crm-filter-row">
                        <select v-model="filterTagId" aria-label="Filtrar por etiqueta" class="wa-crm-select">
                            <option value="">🏷️ Todas las etiquetas</option>
                            <option v-for="t in storeTags" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <select v-model="filterAgentId" aria-label="Filtrar por vendedor" class="wa-crm-select">
                            <option value="">👤 Todos</option>
                            <option value="unassigned">Sin asignar</option>
                            <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                </div>

                <p v-if="loadingList && !conversations.length" class="muted wa-empty-state">Cargando conversaciones…</p>
                <div v-else-if="!conversations.length" class="wa-empty-state">
                    <strong>Aún no hay mensajes</strong>
                    <p class="muted">
                        Cuando envíes una factura/aviso o una clienta escriba al WhatsApp oficial de la tienda, su chat aparecerá aquí automáticamente.
                    </p>
                </div>
                <div v-else-if="!filteredConversations.length" class="wa-empty-state">
                    <strong>Sin chats en este filtro</strong>
                    <p class="muted">Prueba cambiando el filtro de estado, etiqueta o vendedor.</p>
                    <button type="button" class="button secondary" @click="filterMode = 'all'; filterTagId = ''; filterAgentId = ''">Ver todos</button>
                </div>

                <div v-else class="wa-conv-items">
                    <button
                        v-for="conv in filteredConversations"
                        :key="conv.id"
                        type="button"
                        class="wa-conv-item"
                        :class="{ active: selectedId === conv.id, unread: conv.unread_count > 0 }"
                        @click="openConversation(conv.id)"
                    >
                        <span class="wa-conv-avatar" :class="{ online: conv.window_open }">
                            {{ conv.display_name.slice(0, 1).toUpperCase() }}
                        </span>
                        <div class="wa-conv-meta">
                            <div class="wa-conv-top">
                                <strong class="wa-conv-name">{{ conv.display_name }}</strong>
                                <time class="wa-conv-time">{{ formatTime(conv.last_message_at) }}</time>
                            </div>
                            <div class="wa-conv-bottom">
                                <span class="wa-conv-preview">
                                    {{ conv.last_message_direction === 'outbound' ? 'Tú: ' : '' }}{{ conv.last_message_preview || conv.wa_phone }}
                                </span>
                                <span v-if="conv.unread_count > 0" class="wa-unread-pill">{{ conv.unread_count }}</span>
                            </div>
                            <div v-if="conv.tags.length || conv.assigned_user_name || conv.crm_stage !== 'new'" class="wa-conv-crm-badges">
                                <span class="wa-stage-dot-pill" :style="{ color: stageMeta(conv.crm_stage).color, borderColor: stageMeta(conv.crm_stage).color + '55' }">
                                    {{ stageMeta(conv.crm_stage).label }}
                                </span>
                                <span
                                    v-for="t in conv.tags.slice(0, 3)"
                                    :key="t.id"
                                    class="wa-tag-mini"
                                    :style="{ background: t.color + '1a', color: t.color }"
                                >
                                    {{ t.name }}
                                </span>
                                <span v-if="conv.assigned_user_name" class="wa-agent-pill">👤 {{ conv.assigned_user_name }}</span>
                            </div>
                        </div>
                    </button>
                </div>
            </aside>

            <!-- Columna derecha: Hilo de mensajes -->
            <section class="panel wa-thread-panel" aria-label="Chat activo">
                <template v-if="activeConversation && selectedId">
                    <header class="wa-thread-header">
                        <button type="button" class="button secondary wa-back-mobile" @click="selectedId = null">
                            ← Chats
                        </button>
                        <div class="wa-thread-identity">
                            <strong>{{ activeConversation.display_name }}</strong>
                            <small class="muted">{{ activeConversation.wa_phone }}</small>
                        </div>
                        <div class="wa-thread-badges">
                            <span class="badge" :class="activeConversation.window_open ? 'wa-window-open' : 'inactive'">
                                {{ activeConversation.window_open ? '✓ Ventana 24h gratis' : 'Fuera de ventana 24h' }}
                            </span>
                            <template v-if="canSend">
                                <button
                                    v-if="!confirmDeleteChat"
                                    type="button"
                                    class="button secondary wa-delete-chat-btn"
                                    title="Eliminar conversación de la bandeja"
                                    @click="confirmDeleteChat = true"
                                >
                                    Eliminar chat
                                </button>
                                <div v-else class="wa-delete-confirm-group">
                                    <button type="button" class="button wa-delete-confirm-yes" :disabled="deletingChat" @click="deleteActiveConversation">
                                        {{ deletingChat ? 'Eliminando…' : 'Sí, eliminar' }}
                                    </button>
                                    <button type="button" class="button secondary wa-delete-chat-btn" :disabled="deletingChat" @click="confirmDeleteChat = false">
                                        Cancelar
                                    </button>
                                </div>
                            </template>
                        </div>
                    </header>

                    <!-- Barra CRM del chat: Etapa + Vendedor + Etiquetas -->
                    <div v-if="canSend" class="wa-thread-crm-bar">
                        <label class="wa-crm-inline-field">
                            <span>Etapa:</span>
                            <select
                                class="wa-crm-select"
                                :value="activeConversation.crm_stage || 'new'"
                                :disabled="savingCrm"
                                @change="onStageSelect(activeConversation.id, $event)"
                            >
                                <option v-for="st in crmStages" :key="st.key" :value="st.key">{{ st.label }}</option>
                            </select>
                        </label>
                        <label class="wa-crm-inline-field">
                            <span>Asignado:</span>
                            <select
                                class="wa-crm-select"
                                :value="activeConversation.assigned_user_id ?? ''"
                                :disabled="savingCrm"
                                @change="onAgentSelect"
                            >
                                <option value="">Sin asignar</option>
                                <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>
                        </label>
                        <div class="wa-thread-tags-bar">
                            <button
                                v-for="tag in storeTags"
                                :key="tag.id"
                                type="button"
                                class="wa-tag-toggle"
                                :class="{ active: (activeConversation.tags || []).some((t: CrmTag) => t.id === tag.id) }"
                                :style="
                                    (activeConversation.tags || []).some((t: CrmTag) => t.id === tag.id)
                                        ? { background: tag.color, borderColor: tag.color, color: '#fff' }
                                        : { color: tag.color, borderColor: tag.color + '55' }
                                "
                                :disabled="savingCrm"
                                @click="toggleConversationTag(tag.id)"
                            >
                                {{ (activeConversation.tags || []).some((t: CrmTag) => t.id === tag.id) ? '✓ ' : '+ ' }}{{ tag.name }}
                            </button>
                        </div>
                    </div>

                    <!-- Pedidos / apartados vinculados al cliente -->
                    <div v-if="customerContext && customerContext.recent_orders.length" class="wa-customer-orders">
                        <span class="wa-orders-label">Pedidos activos:</span>
                        <button
                            v-for="ord in customerContext.recent_orders"
                            :key="ord.id"
                            type="button"
                            class="wa-order-chip"
                            @click="emit('open-order', ord.id)"
                        >
                            <span>Apartado {{ ord.label }}</span>
                            <em class="wa-order-status">{{ orderStatusLabel(ord.status) }}</em>
                            <strong>{{ ord.currency === 'USD' ? 'US$' : ord.currency }} {{ ord.grand_total }}</strong>
                        </button>
                    </div>

                    <!-- Solicitudes pendientes de depósito (no reservan stock hasta confirmar) -->
                    <div v-if="reqNotice" class="wa-deposit-notice" role="status">{{ reqNotice }}</div>
                    <div v-if="pendingRequests.length && canSend" class="wa-deposit-strip" aria-label="Solicitudes pendientes de depósito">
                        <div class="wa-deposit-head">
                            <strong>⏳ {{ pendingRequests.length }} {{ pendingRequests.length === 1 ? 'solicitud pendiente de depósito' : 'solicitudes pendientes de depósito' }}</strong>
                            <span>El inventario sigue disponible hasta que confirmes el pago</span>
                        </div>
                        <div v-for="req in pendingRequests" :key="req.id" class="wa-deposit-card">
                            <div class="wa-deposit-info">
                                <strong>{{ req.product_name }} <span v-if="req.variant_label">({{ req.variant_label }})</span></strong>
                                <div class="wa-deposit-meta">
                                    <span>SKU: {{ req.variant_sku }}</span>
                                    <span class="wa-nowrap">Precio: US$ {{ req.unit_price }}</span>
                                    <span class="wa-nowrap">Depósito: <strong>US$ {{ req.required_deposit }}</strong></span>
                                    <span :class="req.in_stock ? 'wa-stock-ok' : 'wa-stock-out'">
                                        {{ req.in_stock ? (req.available_stock !== null ? `Disponible: ${req.available_stock}` : 'Disponible') : 'Sin stock ahora' }}
                                    </span>
                                </div>
                            </div>
                            <div class="wa-deposit-controls">
                                <label class="wa-deposit-amt">
                                    <span>Abono recibido (US$)</span>
                                    <input v-model="reqDepositById[req.id]" type="number" min="0" step="0.01" inputmode="decimal" :disabled="reqBusyId === req.id" />
                                </label>
                                <label class="wa-deposit-trust">
                                    <input v-model="reqTrustById[req.id]" type="checkbox" :disabled="reqBusyId === req.id" />
                                    <span>Clienta de confianza</span>
                                </label>
                                <div class="wa-deposit-btns">
                                    <button
                                        type="button"
                                        class="button primary wa-deposit-confirm"
                                        :disabled="reqBusyId === req.id || !req.in_stock"
                                        @click="confirmDepositRequest(req)"
                                    >
                                        {{ reqBusyId === req.id ? 'Confirmando…' : '✓ Confirmar y apartar' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="button secondary wa-deposit-cancel"
                                        :disabled="reqBusyId === req.id"
                                        @click="cancelDepositRequest(req)"
                                    >
                                        Descartar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mensajes -->
                    <div ref="threadEl" class="wa-messages-scroll">
                        <p v-if="loadingThread && !messages.length" class="muted wa-empty-state">Cargando mensajes…</p>
                        <div
                            v-for="m in messages"
                            :key="m.id"
                            class="wa-bubble-row"
                            :class="m.is_internal ? 'internal' : m.direction === 'outbound' ? 'outbound' : 'inbound'"
                        >
                            <article class="wa-bubble" :class="[m.is_internal ? 'internal' : m.direction, { template: m.message_type === 'template' }]">
                                <div v-if="m.is_internal" class="wa-internal-tag">🔒 Nota interna (invisible para la clienta)</div>
                                <div v-else-if="m.template_name" class="wa-template-tag">Plantilla · {{ m.template_name }}</div>

                                <a
                                    v-if="m.has_media && m.message_type === 'image'"
                                    :href="mediaUrl(m.id)"
                                    target="_blank"
                                    rel="noopener"
                                    class="wa-media-image-link"
                                >
                                    <img :src="mediaUrl(m.id)" :alt="m.body || 'Imagen de WhatsApp'" loading="lazy" />
                                </a>

                                <a
                                    v-else-if="m.has_media && m.message_type === 'document'"
                                    :href="mediaUrl(m.id)"
                                    target="_blank"
                                    rel="noopener"
                                    class="wa-media-doc-link"
                                >
                                    📄 {{ m.media_filename || 'Abrir documento adjunto' }}
                                </a>

                                <audio v-else-if="m.has_media && m.message_type === 'audio'" controls preload="none" :src="mediaUrl(m.id)" class="wa-audio-player"></audio>

                                <p
                                    v-if="m.body && !(m.has_media && m.message_type === 'image' && (m.body === '📷 Imagen recibida' || m.body === '📷 Imagen enviada'))"
                                    class="wa-bubble-text"
                                >
                                    {{ m.body }}
                                </p>

                                <footer class="wa-bubble-foot">
                                    <span v-if="m.sender_user_name && (m.direction === 'outbound' || m.is_internal)">{{ m.sender_user_name }} · </span>
                                    <time>{{ formatDateTime(m.occurred_at) }}</time>
                                    <span v-if="m.direction === 'outbound' && !m.is_internal" class="wa-ticks" :class="{ failed: m.status === 'failed' }">
                                        · {{ statusTicks(m) }}
                                    </span>
                                </footer>
                            </article>
                        </div>
                    </div>

                    <!-- Barra de respuesta / Nota interna / Slash Quick Replies -->
                    <footer class="wa-composer" :class="{ 'note-mode': composerMode === 'note' }">
                        <p v-if="sendError" class="notice error wa-send-error" role="alert">{{ sendError }}</p>

                        <div v-if="canSend" class="wa-composer-mode-bar">
                            <button
                                type="button"
                                class="wa-mode-pill"
                                :class="{ active: composerMode === 'whatsapp' }"
                                @click="composerMode = 'whatsapp'"
                            >
                                💬 WhatsApp
                            </button>
                            <button
                                type="button"
                                class="wa-mode-pill note"
                                :class="{ active: composerMode === 'note' }"
                                @click="composerMode = 'note'; qrPopoverOpen = false; clearAttachment()"
                            >
                                🔒 Nota interna
                            </button>
                            <span v-if="composerMode === 'whatsapp'" class="wa-slash-hint">Escribe <code>/</code> para respuestas rápidas</span>
                            <span v-else class="wa-slash-hint">Solo visible para tu equipo (no se envía al cliente)</span>
                        </div>

                        <!-- Autocompletado de respuestas rápidas (/envio, /zelle, /horario) -->
                        <div v-if="slashSuggestions.length && composerMode === 'whatsapp'" class="wa-slash-popover" role="listbox">
                            <button
                                v-for="q in slashSuggestions"
                                :key="q.id"
                                type="button"
                                class="wa-slash-item"
                                @click="applyQuickReply(q)"
                            >
                                <div class="wa-slash-item-top">
                                    <code>/{{ q.shortcut }}</code>
                                    <strong>{{ q.title }}</strong>
                                </div>
                                <span class="muted">{{ q.body }}</span>
                            </button>
                        </div>

                        <div v-if="composerMode === 'whatsapp' && !activeConversation.window_open" class="wa-window-closed-hint">
                            Han pasado más de 24 horas desde el último mensaje de esta clienta. Puedes dejar una <strong>🔒 Nota interna</strong> aquí o enviar una plantilla desde <strong>Apartados</strong>.
                        </div>

                        <template v-else-if="canSend">
                            <div v-if="attachment && composerMode === 'whatsapp'" class="wa-attachment-pill">
                                <span>📎 {{ attachment.name }}</span>
                                <button type="button" class="text-button" @click="clearAttachment">Quitar ×</button>
                            </div>
                            <form class="wa-composer-row" @submit.prevent="sendMessage">
                                <template v-if="composerMode === 'whatsapp'">
                                    <input
                                        ref="fileInput"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,application/pdf"
                                        class="sr-only"
                                        @change="onPickFile"
                                    />
                                    <button
                                        type="button"
                                        class="button secondary wa-attach-btn"
                                        :disabled="sending"
                                        title="Adjuntar imagen o PDF"
                                        aria-label="Adjuntar imagen o PDF"
                                        @click="fileInput?.click()"
                                    >
                                        📎
                                    </button>
                                    <button
                                        type="button"
                                        class="button secondary wa-attach-btn"
                                        :disabled="sending"
                                        title="Insertar respuesta rápida"
                                        aria-label="Insertar respuesta rápida"
                                        @click="qrPopoverOpen = !qrPopoverOpen"
                                    >
                                        ⚡
                                    </button>
                                </template>
                                <textarea
                                    v-model="draftText"
                                    rows="1"
                                    maxlength="4096"
                                    :placeholder="composerMode === 'note' ? 'Escribe una nota interna para el equipo…' : 'Escribe una respuesta gratis o /envio, /zelle…'"
                                    :disabled="sending"
                                    @keydown="onComposerKeydown"
                                ></textarea>
                                <button
                                    type="submit"
                                    class="button wa-send-btn"
                                    :class="composerMode === 'note' ? 'wa-note-submit-btn' : 'primary'"
                                    :disabled="sending || (!draftText.trim() && !attachment)"
                                >
                                    {{ sending ? 'Guardando…' : composerMode === 'note' ? 'Guardar nota' : 'Enviar' }}
                                </button>
                            </form>
                        </template>
                    </footer>
                </template>

                <div v-else class="wa-empty-thread">
                    <strong>Selecciona una conversación</strong>
                    <p class="muted">Elige un chat de la izquierda para leer los mensajes, asignar vendedor, agregar etiquetas o responder.</p>
                </div>
            </section>
        </div>
    </section>
</template>

<style scoped>
.wa-inbox-shell {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.wa-crm-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
}
.wa-view-tabs {
    display: inline-flex;
    background: #f1eefb;
    padding: 4px;
    border-radius: 12px;
    gap: 4px;
}
.wa-view-tab {
    border: 0;
    background: transparent;
    color: #4b5563;
    font-weight: 700;
    font-size: 0.86rem;
    padding: 8px 14px;
    border-radius: 9px;
    cursor: pointer;
    white-space: nowrap;
}
.wa-view-tab.active {
    background: #fff;
    color: #4c1d95;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.08);
}
.wa-crm-tools {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.wa-tool-btn {
    min-height: 36px;
    padding: 6px 12px;
    font-size: 0.83rem;
    white-space: nowrap;
}
.wa-tool-btn.active {
    background: #efeaff;
    border-color: #c4b5fd;
    color: #4c1d95;
}
.wa-manager-panel {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 14px 16px;
}
.wa-manager-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.wa-manager-tags-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.wa-tag-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    border: 1px solid transparent;
    font-size: 0.8rem;
    font-weight: 700;
}
.wa-tag-remove {
    border: 0;
    background: transparent;
    color: inherit;
    cursor: pointer;
    font-weight: 800;
    padding: 0 2px;
}
.wa-manager-form {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.wa-manager-form input {
    flex: 1;
    min-width: 180px;
}
.wa-color-swatches {
    display: inline-flex;
    gap: 6px;
}
.wa-color-dot {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
}
.wa-color-dot.active {
    border-color: #17152c;
    transform: scale(1.1);
}
.wa-qr-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: 200px;
    overflow-y: auto;
}
.wa-qr-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 10px;
    border-radius: 8px;
    background: #faf9ff;
    border: 1px solid #eceaf6;
}
.wa-qr-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.wa-qr-info code {
    color: #6d28d9;
    font-weight: 700;
    font-size: 0.82rem;
}
.wa-qr-info p {
    margin: 0;
    font-size: 0.82rem;
}
.wa-qr-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 8px;
}
.wa-qr-form textarea {
    grid-column: 1 / -1;
}
.wa-inbox-banners {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.wa-banner-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.wa-banner-row .button {
    white-space: nowrap;
    flex-shrink: 0;
}

/* KANBAN */
.wa-kanban-shell {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.wa-kanban-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.wa-kanban-search {
    flex: 1;
    min-width: 200px;
}
.wa-kanban-board {
    display: grid;
    grid-template-columns: repeat(5, minmax(230px, 1fr));
    gap: 12px;
    overflow-x: auto;
    padding-bottom: 8px;
}
.wa-kanban-col {
    background: #f6f5fb;
    border: 1px solid #e6e3f5;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    min-height: 460px;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.wa-kanban-col.drag-over {
    background: #ede9fe;
    border-color: #7c3aed;
}
.wa-kanban-col-head {
    padding: 10px 12px;
    border-top: 4px solid #6d28d9;
    border-bottom: 1px solid #e6e3f5;
    border-radius: 11px 11px 0 0;
    background: #fff;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.wa-kanban-col-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
.wa-kanban-col-sum {
    font-size: 0.78rem;
    font-weight: 700;
    color: #4c1d95;
    white-space: nowrap;
}
.wa-kanban-cards {
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
}
.wa-kanban-empty {
    font-size: 0.82rem;
    text-align: center;
    margin: 24px 0;
}
.wa-kanban-card {
    background: #fff;
    border: 1px solid #e8e5f5;
    border-radius: 10px;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 5px;
    box-shadow: 0 1px 2px rgba(16, 24, 40, 0.05);
    cursor: grab;
}
.wa-kanban-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
.wa-kanban-preview {
    margin: 0;
    font-size: 0.8rem;
    color: #4b5563;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.wa-card-tags,
.wa-conv-crm-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 3px;
}
.wa-tag-mini {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 999px;
    white-space: nowrap;
}
.wa-stage-dot-pill {
    font-size: 0.69rem;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 999px;
    border: 1px solid;
    white-space: nowrap;
}
.wa-agent-pill {
    font-size: 0.7rem;
    color: #4b5563;
    background: #f3f4f6;
    padding: 1px 6px;
    border-radius: 999px;
    white-space: nowrap;
}
.wa-kanban-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    flex-wrap: wrap;
}
.wa-kanban-money {
    font-size: 0.78rem;
    font-weight: 800;
    color: #166534;
    background: #dcfce7;
    padding: 2px 7px;
    border-radius: 999px;
    white-space: nowrap;
}
.wa-kanban-card-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    margin-top: 4px;
    padding-top: 6px;
    border-top: 1px solid #f2f0fa;
}
.wa-stage-mini-select {
    font-size: 0.74rem;
    padding: 3px 6px;
    border-radius: 6px;
    min-height: 28px;
    max-width: 112px;
}
.wa-open-chat-btn {
    padding: 4px 8px;
    min-height: 28px;
    font-size: 0.75rem;
    white-space: nowrap;
}

/* INBOX GRID */
.wa-inbox-grid {
    display: grid;
    grid-template-columns: 350px minmax(0, 1fr);
    gap: 14px;
    min-height: 70vh;
    max-height: calc(100dvh - 205px);
}
.wa-conv-list-panel,
.wa-thread-panel {
    display: flex;
    flex-direction: column;
    padding: 0;
    overflow: hidden;
    min-height: 520px;
}
.wa-conv-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 14px 16px 10px;
    border-bottom: 1px solid #eceaf6;
}
.wa-conv-header h2 {
    margin: 0;
    font-size: 1.05rem;
}
.wa-refresh-btn {
    padding: 6px 12px;
    min-height: 36px;
    font-size: 0.85rem;
    white-space: nowrap;
}
.wa-conv-search {
    padding: 10px 14px;
    border-bottom: 1px solid #eceaf6;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.wa-conv-search input {
    width: 100%;
}
.wa-crm-filter-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}
.wa-crm-select {
    font-size: 0.8rem;
    padding: 5px 8px;
    min-height: 32px;
    border-radius: 8px;
}
.wa-filter-pills {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.wa-filter-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 999px;
    border: 1px solid #e2dff2;
    background: #f8f7fc;
    color: #4b5563;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.wa-filter-pill:hover {
    background: #efeaff;
    color: #4c1d95;
}
.wa-filter-pill.active {
    background: #6d28d9;
    border-color: #6d28d9;
    color: #fff;
}
.wa-filter-count {
    font-size: 0.72rem;
    padding: 1px 6px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.08);
}
.wa-filter-pill.active .wa-filter-count {
    background: rgba(255, 255, 255, 0.22);
    color: #fff;
}
.wa-filter-count.highlight {
    background: #16a34a;
    color: #fff;
}
.wa-conv-items {
    flex: 1;
    overflow-y: auto;
}
.wa-conv-item {
    width: 100%;
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 12px 14px;
    border: 0;
    border-bottom: 1px solid #f2f0fa;
    background: transparent;
    text-align: left;
    cursor: pointer;
    transition: background 0.15s ease;
}
.wa-conv-item:hover {
    background: #f8f6ff;
}
.wa-conv-item.active {
    background: #efeaff;
}
.wa-conv-item.unread .wa-conv-name {
    color: #17152c;
    font-weight: 800;
}
.wa-conv-avatar {
    position: relative;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #ede9fe;
    color: #5b21b6;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.wa-conv-avatar.online::after {
    content: '';
    position: absolute;
    right: 1px;
    bottom: 1px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #16a34a;
    border: 2px solid #fff;
}
.wa-conv-meta {
    flex: 1;
    min-width: 0;
}
.wa-conv-top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}
.wa-conv-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.93rem;
}
.wa-conv-time {
    font-size: 0.74rem;
    color: #6b7280;
    white-space: nowrap;
    flex-shrink: 0;
}
.wa-conv-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 2px;
}
.wa-conv-preview {
    font-size: 0.82rem;
    color: #6b7280;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.wa-unread-pill {
    background: #16a34a;
    color: #fff;
    font-size: 0.74rem;
    font-weight: 700;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.wa-thread-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 16px;
    border-bottom: 1px solid #eceaf6;
    flex-wrap: wrap;
}
.wa-thread-identity {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
}
.wa-thread-identity strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.wa-thread-badges {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.wa-delete-confirm-group {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.wa-delete-chat-btn {
    padding: 5px 10px;
    min-height: 32px;
    font-size: 0.78rem;
    white-space: nowrap;
}
.wa-delete-confirm-yes {
    padding: 5px 10px;
    min-height: 32px;
    font-size: 0.78rem;
    white-space: nowrap;
    background: #dc2626;
    border-color: #dc2626;
    color: #fff;
}
.wa-window-open {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
    white-space: nowrap;
}
.wa-back-mobile {
    display: none;
    min-height: 36px;
    padding: 6px 10px;
    white-space: nowrap;
}
.wa-thread-crm-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px;
    background: #fcfbff;
    border-bottom: 1px solid #eceaf6;
    flex-wrap: wrap;
}
.wa-crm-inline-field {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    font-weight: 700;
    color: #4b5563;
}
.wa-thread-tags-bar {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
}
.wa-tag-toggle {
    font-size: 0.74rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 999px;
    border: 1px solid;
    background: #fff;
    cursor: pointer;
    white-space: nowrap;
}
.wa-customer-orders {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #faf9ff;
    border-bottom: 1px solid #eceaf6;
    overflow-x: auto;
}
.wa-orders-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #6b7280;
    white-space: nowrap;
}
.wa-order-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    border: 1px solid #ddd6fe;
    background: #fff;
    color: #4c1d95;
    font-size: 0.8rem;
    white-space: nowrap;
    cursor: pointer;
}
.wa-order-status {
    font-style: normal;
    font-size: 0.72rem;
    padding: 1px 6px;
    border-radius: 999px;
    background: #f3f0ff;
    color: #5b21b6;
}
.wa-messages-scroll {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: #f6f5fb;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.wa-bubble-row {
    display: flex;
}
.wa-bubble-row.outbound,
.wa-bubble-row.internal {
    justify-content: flex-end;
}
.wa-bubble-row.inbound {
    justify-content: flex-start;
}
.wa-bubble {
    max-width: min(78%, 520px);
    padding: 10px 13px;
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(16, 24, 40, 0.06);
    word-break: break-word;
}
.wa-bubble.inbound {
    background: #ffffff;
    color: #17152c;
    border-bottom-left-radius: 4px;
}
.wa-bubble.outbound {
    background: #dcfce7;
    color: #143321;
    border-bottom-right-radius: 4px;
}
.wa-bubble.internal {
    background: #fef3c7;
    color: #78350f;
    border: 1px dashed #f59e0b;
    border-bottom-right-radius: 4px;
}
.wa-bubble.template {
    background: #ede9fe;
    color: #2e1065;
}
.wa-internal-tag {
    font-size: 0.72rem;
    font-weight: 800;
    color: #92400e;
    margin-bottom: 4px;
}
.wa-template-tag {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    opacity: 0.75;
    margin-bottom: 4px;
}
.wa-bubble-text {
    margin: 0;
    white-space: pre-wrap;
    line-height: 1.42;
    font-size: 0.93rem;
}
.wa-media-image-link img {
    display: block;
    max-width: 240px;
    max-height: 260px;
    border-radius: 10px;
    margin-bottom: 6px;
    object-fit: cover;
}
.wa-media-doc-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.75);
    color: inherit;
    font-weight: 600;
    text-decoration: none;
    margin-bottom: 4px;
}
.wa-audio-player {
    max-width: 240px;
    height: 36px;
}
.wa-bubble-foot {
    margin-top: 5px;
    font-size: 0.72rem;
    opacity: 0.75;
    text-align: right;
}
.wa-ticks.failed {
    color: #b91c1c;
    font-weight: 700;
}
.wa-composer {
    position: relative;
    padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid #eceaf6;
    background: #fff;
}
.wa-composer.note-mode {
    background: #fffbeb;
    border-top-color: #fde68a;
}
.wa-composer-mode-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.wa-mode-pill {
    border: 1px solid #e2dff2;
    background: #f8f7fc;
    color: #4b5563;
    font-size: 0.76rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 999px;
    cursor: pointer;
}
.wa-mode-pill.active {
    background: #6d28d9;
    border-color: #6d28d9;
    color: #fff;
}
.wa-mode-pill.note.active {
    background: #d97706;
    border-color: #d97706;
    color: #fff;
}
.wa-slash-hint {
    font-size: 0.74rem;
    color: #6b7280;
}
.wa-slash-popover {
    margin-bottom: 8px;
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #ddd6fe;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 6px 18px rgba(16, 24, 40, 0.12);
    display: flex;
    flex-direction: column;
}
.wa-slash-item {
    border: 0;
    border-bottom: 1px solid #f2f0fa;
    background: transparent;
    text-align: left;
    padding: 8px 12px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.wa-slash-item:hover {
    background: #f5f3ff;
}
.wa-slash-item-top {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.84rem;
}
.wa-slash-item-top code {
    color: #6d28d9;
    font-weight: 800;
}
.wa-slash-item span {
    font-size: 0.78rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.wa-send-error {
    margin-bottom: 8px;
}
.wa-window-closed-hint {
    font-size: 0.85rem;
    color: #4b5563;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px 12px;
}
.wa-attachment-pill {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 6px 10px;
    margin-bottom: 8px;
    background: #f3f0ff;
    border-radius: 8px;
    font-size: 0.84rem;
}
.wa-composer-row {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}
.wa-composer-row textarea {
    flex: 1;
    min-height: 42px;
    max-height: 120px;
    resize: vertical;
    padding: 10px 12px;
    border-radius: 10px;
}
.wa-attach-btn {
    min-height: 42px;
    padding: 0 11px;
    flex-shrink: 0;
}
.wa-send-btn {
    min-height: 42px;
    white-space: nowrap;
    flex-shrink: 0;
}
.wa-note-submit-btn {
    background: #d97706;
    border-color: #d97706;
    color: #fff;
}
.wa-empty-state,
.wa-empty-thread {
    padding: 36px 20px;
    text-align: center;
    margin: auto;
    max-width: 360px;
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
}

@media (max-width: 899px) {
    .wa-inbox-grid {
        grid-template-columns: 1fr;
        max-height: none;
    }
    .wa-inbox-shell.mobile-thread-open .wa-conv-list-panel {
        display: none;
    }
    .wa-inbox-shell:not(.mobile-thread-open) .wa-thread-panel {
        display: none;
    }
    .wa-thread-panel {
        height: calc(100dvh - 165px);
        min-height: 440px;
    }
    .wa-back-mobile {
        display: inline-flex;
    }
    .wa-bubble {
        max-width: 88%;
    }
}
.wa-deposit-notice {
    margin: 6px 14px 0;
    padding: 8px 12px;
    border-radius: 10px;
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
    font-size: 0.84rem;
    font-weight: 600;
}
.wa-deposit-strip {
    margin: 8px 14px 4px;
    padding: 10px 12px;
    border-radius: 12px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    display: grid;
    gap: 8px;
}
.wa-deposit-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    font-size: 0.82rem;
    color: #92400e;
}
.wa-deposit-card {
    background: #fff;
    border: 1px solid #f3e4b2;
    border-radius: 10px;
    padding: 10px;
    display: grid;
    gap: 8px;
}
.wa-deposit-info {
    display: grid;
    gap: 4px;
    font-size: 0.84rem;
    color: #1f2937;
}
.wa-deposit-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    font-size: 0.78rem;
    color: #5b556e;
}
.wa-nowrap { white-space: nowrap; }
.wa-stock-ok { color: #15803d; font-weight: 700; white-space: nowrap; }
.wa-stock-out { color: #b91c1c; font-weight: 700; white-space: nowrap; }
.wa-deposit-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 8px 12px;
}
.wa-deposit-amt {
    display: grid;
    gap: 3px;
    font-size: 0.74rem;
    color: #6b7280;
}
.wa-deposit-amt input {
    width: 110px;
    padding: 6px 8px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.85rem;
}
.wa-deposit-trust {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    color: #374151;
    min-height: 34px;
    cursor: pointer;
}
.wa-deposit-btns {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-left: auto;
}
.wa-deposit-confirm,
.wa-deposit-cancel {
    min-height: 36px;
    padding: 6px 12px;
    font-size: 0.82rem;
    white-space: nowrap;
}
</style>
