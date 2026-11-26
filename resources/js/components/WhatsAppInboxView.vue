<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Store } from '../types';

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
};

type ChatMessage = {
    id: number;
    direction: 'inbound' | 'outbound';
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

const loadingList = ref(false);
const loadingThread = ref(false);
const sending = ref(false);
const error = ref('');
const sendError = ref('');
const search = ref('');
const filterMode = ref<'all' | 'unread' | 'read' | 'window'>('all');
const whatsappReady = ref(false);
const needsAppSecret = ref(false);
const conversations = ref<ConversationSummary[]>([]);
const filteredConversations = computed(() => {
    if (filterMode.value === 'unread') return conversations.value.filter((c) => c.unread_count > 0);
    if (filterMode.value === 'read') return conversations.value.filter((c) => c.unread_count === 0);
    if (filterMode.value === 'window') return conversations.value.filter((c) => c.window_open);
    return conversations.value;
});
const filterCounts = computed(() => ({
    all: conversations.value.length,
    unread: conversations.value.filter((c) => c.unread_count > 0).length,
    read: conversations.value.filter((c) => c.unread_count === 0).length,
    window: conversations.value.filter((c) => c.window_open).length,
}));
const selectedId = ref<number | null>(props.initialConversationId || null);
const activeConversation = ref<any>(null);
const customerContext = ref<CustomerContext | null>(null);
const messages = ref<ChatMessage[]>([]);
const draftText = ref('');
const attachment = ref<File | null>(null);
const fileInput = ref<HTMLInputElement>();
const threadEl = ref<HTMLElement>();
const notifPermission = ref<string>(typeof Notification !== 'undefined' ? Notification.permission : 'unsupported');

const canSend = computed(() => props.permissions.includes('sales.create'));
const root = computed(() => (props.store?.id ? `/business/stores/${props.store.id}/whatsapp/inbox` : ''));

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
    if (m.direction !== 'outbound') return '';
    if (m.status === 'read') return '✓✓ Leído';
    if (m.status === 'delivered') return '✓✓ Entregado';
    if (m.status === 'sent') return '✓ Enviado';
    if (m.status === 'failed') return '⚠ Falló';
    return '…';
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
        const res = (await api.get(`${root.value}/conversations`, { params: search.value.trim() ? { q: search.value.trim() } : undefined })).data.data;
        whatsappReady.value = !!res.whatsapp_ready;
        needsAppSecret.value = !!res.needs_app_secret;
        conversations.value = res.conversations || [];
    } catch (e) {
        if (!silent) error.value = errorMessage(e);
    } finally {
        if (!silent) loadingList.value = false;
    }
}

async function openConversation(id: number, silent = false) {
    if (!root.value) return;
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

function onPickFile(ev: Event) {
    const target = ev.target as HTMLInputElement;
    const f = target.files?.[0] || null;
    attachment.value = f;
}

function clearAttachment() {
    attachment.value = null;
    if (fileInput.value) fileInput.value.value = '';
}

async function sendMessage() {
    if (!root.value || !selectedId.value || sending.value || !canSend.value) return;
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
        clearAttachment();
        await scrollToBottom();
        await loadConversations(true);
    } catch (e) {
        sendError.value = errorMessage(e);
    } finally {
        sending.value = false;
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
        if (selectedId.value) {
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
    <section class="wa-inbox-shell" :class="{ 'mobile-thread-open': !!selectedId }">
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

        <div class="wa-inbox-grid">
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
                        <button
                            type="button"
                            role="tab"
                            class="wa-filter-pill"
                            :class="{ active: filterMode === 'all' }"
                            :aria-selected="filterMode === 'all'"
                            @click="filterMode = 'all'"
                        >
                            Todos <span class="wa-filter-count">{{ filterCounts.all }}</span>
                        </button>
                        <button
                            type="button"
                            role="tab"
                            class="wa-filter-pill"
                            :class="{ active: filterMode === 'unread' }"
                            :aria-selected="filterMode === 'unread'"
                            @click="filterMode = 'unread'"
                        >
                            No leídos <span class="wa-filter-count" :class="{ highlight: filterCounts.unread > 0 }">{{ filterCounts.unread }}</span>
                        </button>
                        <button
                            type="button"
                            role="tab"
                            class="wa-filter-pill"
                            :class="{ active: filterMode === 'read' }"
                            :aria-selected="filterMode === 'read'"
                            @click="filterMode = 'read'"
                        >
                            Leídos <span class="wa-filter-count">{{ filterCounts.read }}</span>
                        </button>
                        <button
                            type="button"
                            role="tab"
                            class="wa-filter-pill"
                            :class="{ active: filterMode === 'window' }"
                            :aria-selected="filterMode === 'window'"
                            @click="filterMode = 'window'"
                        >
                            24h gratis <span class="wa-filter-count">{{ filterCounts.window }}</span>
                        </button>
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
                    <p class="muted">
                        {{ filterMode === 'unread' ? 'No tienes mensajes sin leer.' : filterMode === 'read' ? 'No hay chats leídos todavía.' : 'No hay conversaciones dentro de la ventana de 24 h.' }}
                    </p>
                    <button type="button" class="button secondary" @click="filterMode = 'all'">Ver todos</button>
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
                        </div>
                    </header>

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
                            <strong>{{ ord.currency === 'USD' ? 'US$' : ord.currency }} {{ ord.grand_total }}</strong>
                        </button>
                    </div>

                    <!-- Mensajes -->
                    <div ref="threadEl" class="wa-messages-scroll">
                        <p v-if="loadingThread && !messages.length" class="muted wa-empty-state">Cargando mensajes…</p>
                        <div
                            v-for="m in messages"
                            :key="m.id"
                            class="wa-bubble-row"
                            :class="m.direction === 'outbound' ? 'outbound' : 'inbound'"
                        >
                            <article class="wa-bubble" :class="[m.direction, { template: m.message_type === 'template' }]">
                                <div v-if="m.template_name" class="wa-template-tag">Plantilla · {{ m.template_name }}</div>

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
                                    <span v-if="m.sender_user_name && m.direction === 'outbound'">{{ m.sender_user_name }} · </span>
                                    <time>{{ formatDateTime(m.occurred_at) }}</time>
                                    <span v-if="m.direction === 'outbound'" class="wa-ticks" :class="{ failed: m.status === 'failed' }">
                                        · {{ statusTicks(m) }}
                                    </span>
                                </footer>
                            </article>
                        </div>
                    </div>

                    <!-- Barra de respuesta -->
                    <footer class="wa-composer">
                        <p v-if="sendError" class="notice error wa-send-error" role="alert">{{ sendError }}</p>

                        <div v-if="!activeConversation.window_open" class="wa-window-closed-hint">
                            Han pasado más de 24 horas desde el último mensaje de esta clienta. Para iniciar conversación usa una plantilla desde <strong>Apartados</strong> o <strong>Cuentas por cobrar</strong>; en cuanto ella responda se abrirán 24 h de chat libre gratis.
                        </div>

                        <template v-else-if="canSend">
                            <div v-if="attachment" class="wa-attachment-pill">
                                <span>📎 {{ attachment.name }}</span>
                                <button type="button" class="text-button" @click="clearAttachment">Quitar ×</button>
                            </div>
                            <form class="wa-composer-row" @submit.prevent="sendMessage">
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
                                <textarea
                                    v-model="draftText"
                                    rows="1"
                                    maxlength="4096"
                                    placeholder="Escribe una respuesta gratis…"
                                    :disabled="sending"
                                    @keydown.enter.exact.prevent="sendMessage"
                                ></textarea>
                                <button
                                    type="submit"
                                    class="button primary wa-send-btn"
                                    :disabled="sending || (!draftText.trim() && !attachment)"
                                >
                                    {{ sending ? 'Enviando…' : 'Enviar' }}
                                </button>
                            </form>
                        </template>
                    </footer>
                </template>

                <div v-else class="wa-empty-thread">
                    <strong>Selecciona una conversación</strong>
                    <p class="muted">Elige un chat de la izquierda para leer los mensajes, ver comprobantes y responder sin costo.</p>
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
.wa-inbox-grid {
    display: grid;
    grid-template-columns: 340px minmax(0, 1fr);
    gap: 14px;
    min-height: 70vh;
    max-height: calc(100dvh - 180px);
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
    align-items: center;
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
    width: 42px;
    height: 42px;
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
    font-size: 0.95rem;
}
.wa-conv-time {
    font-size: 0.76rem;
    color: #6b7280;
    white-space: nowrap;
    flex-shrink: 0;
}
.wa-conv-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 3px;
}
.wa-conv-preview {
    font-size: 0.84rem;
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
.wa-bubble-row.outbound {
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
.wa-bubble.template {
    background: #ede9fe;
    color: #2e1065;
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
    opacity: 0.72;
    text-align: right;
}
.wa-ticks.failed {
    color: #b91c1c;
    font-weight: 700;
}
.wa-composer {
    padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid #eceaf6;
    background: #fff;
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
    padding: 0 12px;
    flex-shrink: 0;
}
.wa-send-btn {
    min-height: 42px;
    white-space: nowrap;
    flex-shrink: 0;
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
        height: calc(100dvh - 150px);
        min-height: 440px;
    }
    .wa-back-mobile {
        display: inline-flex;
    }
    .wa-bubble {
        max-width: 88%;
    }
}
</style>
