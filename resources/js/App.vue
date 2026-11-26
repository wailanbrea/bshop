<script setup lang="ts">
import { computed, defineAsyncComponent, h, nextTick, onMounted, onUnmounted, ref, watch, type Component } from 'vue';
import { api, errorMessage } from './api';
import type { Session, Workspace, Warehouse } from './types';
import AuthView from './components/AuthView.vue';

function lazyView(loader: () => Promise<{ default: Component }>) {
    return defineAsyncComponent({
        loader: () =>
            loader().catch((err) => {
                // Recover seamlessly when a PWA/browser tab stays open across a new Vite deploy
                try {
                    const key = 'bshop:chunk-reload-ts';
                    const last = Number(sessionStorage.getItem(key) || 0);
                    if (Date.now() - last > 15000) {
                        sessionStorage.setItem(key, String(Date.now()));
                        window.location.reload();
                    }
                } catch {
                    /* storage unavailable */
                }
                throw err;
            }),
        errorComponent: {
            render: () =>
                h('section', { class: 'panel', style: 'text-align:center;padding:28px 18px;' }, [
                    h('strong', 'Hay una versión nueva disponible'),
                    h('p', { class: 'muted' }, 'Actualiza para cargar la pantalla más reciente.'),
                    h(
                        'button',
                        { type: 'button', class: 'button primary', onClick: () => window.location.reload() },
                        'Actualizar ahora',
                    ),
                ]),
        },
        delay: 150,
    });
}

const BusinessSettings = lazyView(() => import('./components/BusinessSettings.vue'));
const TeamView = lazyView(() => import('./components/TeamView.vue'));
const InviteView = lazyView(() => import('./components/InviteView.vue'));
const ProductsHubView = lazyView(() => import('./components/ProductsHubView.vue'));
import AppIcon from './components/AppIcon.vue';
import SalesWorkspace from './components/SalesWorkspace.vue';
const CustomersView = lazyView(() => import('./components/CustomersView.vue'));
const CustomerLinkView = lazyView(() => import('./components/CustomerLinkView.vue'));
const EmployeesView = lazyView(() => import('./components/EmployeesView.vue'));
const WeeklyAccountsView = lazyView(() => import('./components/WeeklyAccountsView.vue'));
const WeeklySettings = lazyView(() => import('./components/WeeklySettings.vue'));
const InvoicesView = lazyView(() => import('./components/InvoicesView.vue'));
const ReceivablesView = lazyView(() => import('./components/ReceivablesView.vue'));
import BasketBoard from './components/BasketBoard.vue';
const WhatsAppSettings = lazyView(() => import('./components/WhatsAppSettings.vue'));
const WhatsAppAutomations = lazyView(() => import('./components/WhatsAppAutomations.vue'));
const WhatsAppInboxView = lazyView(() => import('./components/WhatsAppInboxView.vue'));
const PaymentMethodSettings = lazyView(() => import('./components/PaymentMethodSettings.vue'));
const InvoiceSettings = lazyView(() => import('./components/InvoiceSettings.vue'));
const ReservationSettings = lazyView(() => import('./components/ReservationSettings.vue'));
const SalesControlView = lazyView(() => import('./components/SalesControlView.vue'));
import {salesControlText} from './locales/es/salesControl';
import { useVisualViewport } from './composables/useVisualViewport';
import { lockPageScroll } from './lib/scrollLock';
import { loadStorePaymentMethods } from './lib/paymentMethods';

useVisualViewport();

const session = ref<Session | null>(null); const workspace = ref<Workspace | null>(null);
const warehouses = ref<Warehouse[]>([]); const storeId = ref<number | ''>('');
const loading = ref(true); const switching = ref(false); const error = ref(''); const menuOpen = ref(false);
const navigation = ref<HTMLElement>(); const menuButton = ref<HTMLButtonElement>();
const drawerQuery = window.matchMedia('(max-width: 1199px)');
const phoneQuery = window.matchMedia('(max-width: 767px)');
const drawerMode = ref(drawerQuery.matches); const phone = ref(phoneQuery.matches);
let unlockMenu: (() => void) | undefined;
function syncLayout() { drawerMode.value = drawerQuery.matches; phone.value = phoneQuery.matches; if (!drawerMode.value) menuOpen.value = false; }
watch(menuOpen, async open => {
    await nextTick();
    if (open && menuOpen.value && drawerMode.value) {
        unlockMenu ??= lockPageScroll();
        navigation.value?.querySelector<HTMLElement>('.drawer-close')?.focus({ preventScroll: true });
    } else if (!menuOpen.value) {
        unlockMenu?.(); unlockMenu = undefined;
        menuButton.value?.focus({ preventScroll: true });
    }
}, { flush: 'post' });
function trapNavigation(event: KeyboardEvent) {
    if (!menuOpen.value || event.key !== 'Tab') return;
    const controls = Array.from(navigation.value?.querySelectorAll<HTMLElement>('a[href],button:not(:disabled)') || []).filter(el => el.getBoundingClientRect().width > 0);
    if (event.shiftKey && document.activeElement === controls[0]) { event.preventDefault(); controls.at(-1)?.focus(); }
    else if (!event.shiftKey && document.activeElement === controls.at(-1)) { event.preventDefault(); controls[0]?.focus(); }
}
const page = ref(location.pathname.includes('/mensajes') ? 'whatsapp-inbox' : location.pathname.includes('/vista-cliente') ? 'customer-links' : location.pathname.includes('/historial-ventas') ? 'history' : location.pathname.includes('/cuentas-semanales') ? 'weekly' : location.pathname.includes('/vendedores') ? 'employees' : location.pathname.includes('/cxc') ? 'receivables' : location.pathname.includes('/apartados') ? 'baskets' : /\/ventas(\/|$)/.test(location.pathname) ? 'sales' : 'overview');
const weeklyContextLocked = ref(false);
const employeeContextLocked = ref(false);
const basketBoard = ref<InstanceType<typeof BasketBoard>>(); const basketContextLocked=ref(false);
const salesVisited = ref(page.value === 'sales'); const salesContextLocked = ref(false);
const salesTitle = ref(location.pathname.includes('/apartados/') ? 'Nuevo apartado' : 'Venta');
const salesWorkspace = ref<InstanceType<typeof SalesWorkspace>>();
const invitationToken = ref(location.pathname.startsWith('/join/') ? location.pathname.split('/').pop() || '' : '');
const waUnreadCount = ref(0);
const waTargetConversationId = ref<number | null>(null);
const waToast = ref<{ conversation_id: number; sender_name: string; preview: string } | null>(null);
let lastSeenInboundMsgId: number | null = null;
let waToastTimer: ReturnType<typeof setTimeout> | null = null;
let waPollInterval: ReturnType<typeof setInterval> | null = null;
const nav = computed(() => [{ id: 'overview', label: 'Vista general', icon: 'home', badge: 0 }, ...(workspace.value?.permissions.includes('sales.view') && workspace.value?.permissions.includes('customers.view') ? [{ id: 'receivables', label: 'Cuentas por cobrar', icon: 'document', badge: 0 },{id:'weekly',label:'Cuentas semanales',icon:'document', badge: 0}] : []), ...(workspace.value?.permissions.includes('sales.view') ? [{ id: 'sales', label: 'Ventas', icon: 'cart', badge: 0 },{id:'history',label:salesControlText.navigation,icon:'document', badge: 0},{id:'baskets',label:'Apartados',icon:'bag', badge: 0},{id:'whatsapp-inbox',label:'Mensajes WhatsApp',icon:'chat', badge: waUnreadCount.value}] : []), ...(workspace.value?.permissions.includes('customers.view') && workspace.value?.permissions.includes('sales.view') && workspace.value?.permissions.includes('sales.create') ? [{ id: 'customer-links', label: 'Vista del cliente', icon: 'users', badge: 0 }] : []), ...(workspace.value?.permissions.includes('customers.view') ? [{ id: 'customers', label: 'Clientes', icon: 'users', badge: 0 }] : []), ...(workspace.value?.permissions.includes('catalog.view') || workspace.value?.permissions.includes('inventory.view') ? [{ id: 'catalog', label: 'Productos', icon: 'box', badge: 0 }] : []), ...(workspace.value?.permissions.includes('invoices.view') ? [{ id: 'invoices', label: 'Facturas', icon: 'document', badge: 0 }] : []), { id: 'stores', label: 'Sucursales', icon: 'store', badge: 0 }, { id: 'warehouses', label: 'Almacenes', icon: 'warehouse', badge: 0 }, ...(workspace.value?.permissions.includes('members.manage') ? [{ id: 'employees', label: 'Vendedores', icon: 'users', badge: 0 },{ id: 'team', label: 'Equipo', icon: 'users', badge: 0 }] : []), { id: 'settings', label: 'Configuración', icon: 'settings', badge: 0 }]);
const store = computed(() => workspace.value?.stores.find(s => s.id === storeId.value));
watch(() => store.value?.id, (id) => { loadStorePaymentMethods(id); lastSeenInboundMsgId = null; checkWhatsAppUnread(true); }, { immediate: true });

function playIncomingChime() {
    try {
        const AudioCtx = window.AudioContext || (window as any).webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const now = ctx.currentTime;
        for (const [freq, start] of [[587.33, 0], [880, 0.12]] as const) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.08, now + start);
            gain.gain.exponentialRampToValueAtTime(0.001, now + start + 0.18);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(now + start);
            osc.stop(now + start + 0.2);
        }
        setTimeout(() => ctx.close().catch(() => {}), 500);
    } catch {
        /* Audio blocked until user gesture */
    }
}

async function checkWhatsAppUnread(initial = false) {
    if (!store.value?.id || !workspace.value?.permissions.includes('sales.view')) {
        waUnreadCount.value = 0;
        return;
    }
    try {
        const res = (await api.get(`/business/stores/${store.value.id}/whatsapp/inbox/unread`)).data.data;
        const count = Number(res.unread_messages || 0);
        waUnreadCount.value = count;
        if ('setAppBadge' in navigator) {
            if (count > 0) (navigator as any).setAppBadge(count).catch(() => {});
            else (navigator as any).clearAppBadge?.().catch(() => {});
        }
        const latest = res.latest_inbound;
        if (latest?.id) {
            if (!initial && lastSeenInboundMsgId !== null && latest.id > lastSeenInboundMsgId) {
                playIncomingChime();
                if (page.value !== 'whatsapp-inbox') {
                    waToast.value = {
                        conversation_id: latest.conversation_id,
                        sender_name: latest.sender_name,
                        preview: latest.preview,
                    };
                    if (waToastTimer) clearTimeout(waToastTimer);
                    waToastTimer = setTimeout(() => { waToast.value = null; }, 9000);
                }
                if (typeof Notification !== 'undefined' && Notification.permission === 'granted' && document.hidden) {
                    const n = new Notification(`WhatsApp · ${latest.sender_name}`, {
                        body: latest.preview,
                        icon: '/pwa-192x192.png',
                    });
                    n.onclick = () => {
                        window.focus();
                        openWhatsAppConversation(latest.conversation_id);
                    };
                }
            }
            lastSeenInboundMsgId = latest.id;
        } else if (initial) {
            lastSeenInboundMsgId = 0;
        }
    } catch {
        /* Silent background poll */
    }
}

function openWhatsAppConversation(conversationId?: number) {
    waToast.value = null;
    if (conversationId) waTargetConversationId.value = conversationId;
    navigate('whatsapp-inbox');
}
async function openWhatsAppSettings() {
    navigate('settings');
    const scrollTarget = () => {
        const el = document.getElementById('whatsapp-app-secret-input') || document.getElementById('whatsapp-settings');
        if (!el) return false;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (el instanceof HTMLInputElement) el.focus({ preventScroll: true });
        return true;
    };
    for (let i = 0; i < 20; i++) {
        await nextTick();
        if (scrollTarget()) {
            // Re-center after async panels above (PaymentMethodSettings / InvoiceSettings) finish loading & expanding
            setTimeout(scrollTarget, 450);
            setTimeout(scrollTarget, 1000);
            return;
        }
        await new Promise((r) => setTimeout(r, 120));
    }
}
const activeBrandName = computed(() => store.value?.name || workspace.value?.business.name || session.value?.business.name || 'BShop');
watch(activeBrandName, name => { if (name) document.title = `${name} · BShop`; }, { immediate: true });
const title = computed(() => nav.value.find(n => n.id === page.value)?.label || 'Mi negocio');
const activeStores = computed(() => workspace.value?.stores.filter(s => s.is_active).length || 0);
const activeWarehouses = computed(() => warehouses.value.filter(w => w.is_active).length);
const roles = computed(() => session.value?.roles.join(', ') || 'Usuario');
const date = computed(() => new Intl.DateTimeFormat('es', { dateStyle: 'long', timeZone: workspace.value?.business.timezone || 'UTC' }).format(new Date()));
let workspaceRequest = 0; let warehouseRequest = 0;
function preferredStore(result: Workspace): number | '' {
    let saved = 0; try { saved = Number(sessionStorage.getItem(`bshop:store:${session.value?.user.id}`)); } catch { /* Read-only preference may be unavailable. */ }
    return result.stores.find(s => s.id === saved && s.is_active)?.id || result.stores.find(s => s.code === 'CENTRAL' && s.is_active)?.id || result.stores.find(s => s.is_active)?.id || result.stores[0]?.id || '';
}
async function loadSession() {
    loading.value = true; error.value = '';
    try {
        session.value = (await api.get('/session')).data.data;
        if (!invitationToken.value) await loadWorkspace();
    } catch (e: any) {
        if (e.response?.status === 401 || e.response?.status === 403) { if (e.response?.status === 403) await api.post('/logout').catch(() => {}); session.value = null; workspace.value = null; }
        else error.value = errorMessage(e);
    } finally { loading.value = false; }
}
async function loadWorkspace() {
    const version = ++workspaceRequest; ++warehouseRequest;
    switching.value = true; error.value = ''; workspace.value = null; warehouses.value = []; storeId.value = '';
    try {
        const result: Workspace = (await api.get('/business/workspace')).data.data;
        if (version !== workspaceRequest) return;
        workspace.value = result; storeId.value = preferredStore(result);
        if (['team','employees'].includes(page.value) && !result.permissions.includes('members.manage')) page.value = 'overview';
        if (['sales','baskets','history','whatsapp-inbox'].includes(page.value) && !result.permissions.includes('sales.view')) page.value = 'overview';
        if (['receivables','weekly'].includes(page.value) && (!result.permissions.includes('sales.view') || !result.permissions.includes('customers.view'))) page.value = 'overview';
        await loadWarehouses();
    } catch (e) { if (version === workspaceRequest) error.value = errorMessage(e); }
    finally { if (version === workspaceRequest) switching.value = false; }
}
async function loadWarehouses() {
    const version = ++warehouseRequest; warehouses.value = [];
    if (!storeId.value || !workspace.value) return;
    try { sessionStorage.setItem(`bshop:store:${session.value?.user.id}`, String(storeId.value)); } catch { /* The authorized server context remains authoritative. */ }
    try {
        const result = (await api.get(`/business/stores/${storeId.value}/warehouses`)).data.data;
        if (version === warehouseRequest) warehouses.value = result;
    } catch (e) { if (version === warehouseRequest) error.value = errorMessage(e); }
}
async function reload() {
    error.value = '';
    try {
        const result: Workspace = (await api.get('/business/workspace')).data.data;
        workspace.value = result;
        if (!result.stores.some(s => s.id === storeId.value)) storeId.value = result.stores[0]?.id || '';
        await loadWarehouses();
    } catch (e) { error.value = errorMessage(e); }
}
async function refreshAccess() {
    try { session.value = (await api.get('/session')).data.data; await reload(); if (page.value === 'team' && !workspace.value?.permissions.includes('members.manage')) navigate('overview'); }
    catch { await loadSession(); }
}
async function logout() {
    menuOpen.value = false;
    try { await api.post('/logout'); session.value = null; workspace.value = null; warehouses.value = []; if (!invitationToken.value) history.replaceState({}, '', '/login'); }
    catch (e) { error.value = errorMessage(e); }
}
function navigate(id: string) { page.value = id; if (id === 'sales') { salesVisited.value = true; history.replaceState({}, '', '/app/ventas'); } else if(id==='whatsapp-inbox')history.replaceState({},'', '/app/mensajes'); else if(id==='customer-links')history.replaceState({},'', '/app/vista-cliente');else if(id==='history')history.replaceState({},'', '/app/historial-ventas');else if(id==='weekly')history.replaceState({},'', '/app/cuentas-semanales');else if(id==='employees')history.replaceState({},'', '/app/vendedores');else if(id==='receivables')history.replaceState({},'', '/app/cxc');else if(id==='baskets')history.replaceState({},'', '/app/apartados');else if (!invitationToken.value) history.replaceState({}, '', '/'); menuOpen.value = false; window.scrollTo({ top: 0, behavior: 'auto' }); }
async function openSavedOrder(id?: number) { navigate('baskets'); await nextTick(); if(id)await basketBoard.value?.select(id); }
async function invitationAccepted() { invitationToken.value = ''; history.replaceState({}, '', '/'); await loadSession(); }
function onVisibilityChange() { if (!document.hidden) checkWhatsAppUnread(false); }
onMounted(loadSession);
onMounted(() => { drawerQuery.addEventListener('change', syncLayout); phoneQuery.addEventListener('change', syncLayout); document.addEventListener('visibilitychange', onVisibilityChange); waPollInterval = setInterval(() => { if (!document.hidden) checkWhatsAppUnread(false); }, 12000); });
onUnmounted(() => { unlockMenu?.(); if (waPollInterval) clearInterval(waPollInterval); if (waToastTimer) clearTimeout(waToastTimer); drawerQuery.removeEventListener('change', syncLayout); phoneQuery.removeEventListener('change', syncLayout); document.removeEventListener('visibilitychange', onVisibilityChange); });
</script>

<template>
    <div v-if="loading" class="initial-loading" role="status"><span class="brand-mark">b<span>•</span></span><p>Preparando tu espacio.</p></div>
    <InviteView v-else-if="invitationToken" :token="invitationToken" :session="session" @accepted="invitationAccepted" @authenticated="loadSession" @logout="logout" />
    <AuthView v-else-if="!session && !error" @authenticated="loadSession" />
    <div v-else-if="!session" class="initial-loading"><p class="notice error" role="alert">{{ error }}</p><button class="button primary" @click="loadSession">Reintentar conexión</button></div>
    <div v-else class="app-shell">
        <button v-if="menuOpen" class="sidebar-scrim" tabindex="-1" aria-hidden="true" @click="menuOpen = false"></button>
        <aside ref="navigation" id="main-navigation" class="sidebar" :class="{ open: menuOpen }" :inert="phone && !menuOpen ? true : undefined" :aria-hidden="phone && !menuOpen ? true : undefined" :role="menuOpen ? 'dialog' : undefined" :aria-modal="menuOpen ? true : undefined" aria-label="Menú de navegación" @keydown="trapNavigation" @keydown.esc="menuOpen = false">
            <button v-if="drawerMode && menuOpen" type="button" class="icon-button drawer-close" aria-label="Cerrar menú" @click="menuOpen = false">×</button>
            <a href="/" class="brand" :aria-label="`${activeBrandName} inicio`"><span class="brand-mark" aria-hidden="true">{{ activeBrandName.slice(0, 1).toUpperCase() }}</span><span class="brand-word"><strong>{{ activeBrandName }}</strong><small>Gestión de tienda</small></span></a>
            <div class="workspace-switch"><span class="eyebrow">TU NEGOCIO</span><strong class="business-name">{{ activeBrandName }}</strong><span class="workspace-subtitle">Tu negocio y sus sucursales</span></div>
            <span class="nav-caption">PRINCIPAL</span><nav aria-label="Navegación principal"><button v-for="item in nav" :key="item.id" class="nav-item" :class="{ active: page === item.id }" :aria-label="item.label" :aria-current="page === item.id ? 'page' : undefined" @click="navigate(item.id)"><span><AppIcon :name="item.icon" /></span><span class="nav-label">{{ item.label }}</span><span v-if="item.badge" class="wa-nav-badge">{{ item.badge > 99 ? '99+' : item.badge }}</span><span v-else-if="page === item.id" class="nav-active-dot"></span></button></nav>
            <div class="sidebar-bottom"><div class="support-card"><span><AppIcon name="store" /></span><strong>Mi tienda</strong><p>{{ activeBrandName }}</p></div><div class="user-block"><span class="avatar">{{ session.user.name.slice(0, 1) }}</span><div><strong>{{ session.user.name }}</strong><small>{{ roles }}</small></div><button class="icon-button" @click="logout" aria-label="Cerrar sesión" title="Cerrar sesión">↪</button></div></div>
        </aside>
        <div class="main-shell" :inert="menuOpen ? true : undefined">
            <header class="topbar">
                <div class="breadcrumb"><button ref="menuButton" type="button" class="icon-button menu-toggle" @click="menuOpen = !menuOpen" aria-label="Abrir menú" aria-controls="main-navigation" :aria-expanded="menuOpen">☰</button><div v-if="page === 'sales'" class="sales-header-title"><h1>{{ salesTitle }}</h1><p>Ventas y apartados de tu negocio</p></div><template v-else><span class="breadcrumb-parent">Mi negocio</span><span class="breadcrumb-slash">/</span><strong>{{ title }}</strong></template></div>
                <label v-if="workspace" class="branch-picker"><span>SUCURSAL ACTIVA</span><select v-model="storeId" :disabled="(page === 'sales' && salesContextLocked)||(page==='baskets'&&basketContextLocked)||(page==='weekly'&&weeklyContextLocked)||(page==='employees'&&employeeContextLocked)" @change="loadWarehouses"><option v-for="branch in workspace.stores" :key="branch.id" :value="branch.id">{{ branch.name }}{{ branch.is_active ? '' : ' · Inactiva' }}</option><option v-if="!workspace.stores.length" value="">Sin acceso a sucursales</option></select></label>
                <div class="topbar-right"><button v-if="waUnreadCount > 0 && page !== 'whatsapp-inbox'" type="button" class="wa-topbar-pill" @click="openWhatsAppConversation()">💬 {{ waUnreadCount }} nuevo{{ waUnreadCount === 1 ? '' : 's' }}</button><span class="secure-label"><span class="status-dot"></span> Sesión segura</span><span class="avatar small">{{ session.user.name.slice(0, 1) }}</span><div class="topbar-profile"><strong>{{ session.user.name }}</strong><small>{{ roles }}</small></div></div>
            </header>
            <div v-if="waToast" class="wa-live-toast" role="status" aria-live="polite">
                <div class="wa-live-toast-body" @click="openWhatsAppConversation(waToast.conversation_id)">
                    <strong>💬 Nuevo WhatsApp · {{ waToast.sender_name }}</strong>
                    <p>{{ waToast.preview }}</p>
                </div>
                <div class="wa-live-toast-actions">
                    <button type="button" class="button primary" @click="openWhatsAppConversation(waToast.conversation_id)">Responder</button>
                    <button type="button" class="icon-button" aria-label="Cerrar aviso" @click="waToast = null">×</button>
                </div>
            </div>
            <main class="main-content" :class="{ 'sales-page': page === 'sales' }">
                <p v-if="error" class="notice error" role="alert">{{ error }} <button class="text-button" @click="loadWorkspace">Reintentar</button></p>
                <div v-if="switching" class="skeleton-grid" aria-label="Cargando negocio" role="status"><div></div><div></div><div></div></div>
                <template v-else-if="workspace">
                    <div v-if="!['sales','baskets','history'].includes(page) && !(page==='overview'&&workspace.permissions.includes('sales.view'))" class="page-heading"><div><span class="eyebrow">{{ date }}</span><h1>{{ page === 'overview' ? `Vista general` : title }}</h1><p>{{ page === 'overview' ? `Consulta la operación de ${activeBrandName}.` : `Administra la operación de ${activeBrandName}.` }}</p></div></div>
                    <template v-if="page === 'overview'">
                        <SalesControlView v-if="workspace.permissions.includes('sales.view')" :key="store?.id||'none'" :store="store" :business="workspace.business" compact @history="navigate('history')" @open-order="openSavedOrder" />
                        <template v-else>
                        <section class="welcome-banner"><div><span class="pill light-pill">{{ activeBrandName }}</span><h2>Tu tienda,<br>en un solo lugar.</h2><p>{{ store?.name || 'Selecciona una sucursal para operar.' }}</p><button v-if="workspace.permissions.includes('sales.view')" class="button cream" @click="navigate('baskets')">Ver apartados <span>→</span></button><button v-else class="button cream" @click="navigate('stores')">Ver sucursales <span>→</span></button></div><div class="banner-art" aria-hidden="true"><div class="art-orbit"></div><div class="art-shop"><span class="art-awning"></span><span class="art-roof"></span><div class="art-window"></div><div class="art-door"></div></div><span class="art-spark">✦</span></div></section>
                        <section class="stats-grid" aria-label="Resumen de configuración"><article class="stat-card"><div class="stat-top"><span>Sucursales activas</span><span class="stat-icon"><AppIcon name="store" /></span></div><strong>{{ activeStores.toString().padStart(2, '0') }}</strong><p>De {{ workspace.stores.length }} sucursales autorizadas</p></article><article class="stat-card"><div class="stat-top"><span>Almacenes activos</span><span class="stat-icon peach"><AppIcon name="warehouse" /></span></div><strong>{{ activeWarehouses.toString().padStart(2, '0') }}</strong><p>En {{ store?.name || 'la sucursal seleccionada' }}</p></article><article class="stat-card"><div class="stat-top"><span>Moneda del negocio</span><span class="stat-icon lavender">$</span></div><strong>{{ workspace.business.default_currency }}</strong><p>Zona horaria: {{ workspace.business.timezone }}</p></article></section>
                        <div class="overview-bottom"><section class="panel setup-panel"><div class="panel-heading"><div><h2>Accesos rápidos</h2></div></div><div class="setup-step"><span class="step-icon done">V</span><div><strong>Datos del negocio</strong><p>Nombre comercial y zona horaria.</p></div><button class="text-button" @click="navigate('settings')">Ver datos →</button></div><div class="setup-step"><span class="step-icon" :class="{ done: activeStores > 0 }">{{ activeStores ? 'V' : '2' }}</span><div><strong>Almacenes</strong><p>Ubicaciones de la sucursal seleccionada.</p></div><button class="text-button" @click="navigate('warehouses')">Revisar →</button></div><div v-if="workspace.permissions.includes('catalog.view')" class="setup-step"><span class="step-icon"><AppIcon name="box" /></span><div><strong>Catálogo</strong><p>Productos, variantes, precios e imágenes.</p></div><button class="text-button" @click="navigate('catalog')">Ver catálogo →</button></div></section><section class="panel context-card"><span class="eyebrow">ESTÁS TRABAJANDO EN</span><span class="context-symbol"><AppIcon name="store" /></span><h2>{{ store?.name || 'Selecciona una sucursal' }}</h2><p>{{ store?.address || 'Añade la dirección y los datos de contacto de esta ubicación.' }}</p><div><span class="badge">{{ store?.code || 'Sin sucursal' }}</span><span v-if="store" class="muted small-text">{{ store.is_active ? 'Activa' : 'Inactiva' }}</span></div><button class="button secondary full" @click="navigate('stores')">Ver sucursales →</button></section></div>
                        </template>
                    </template>
                    <SalesControlView v-else-if="page==='history'&&workspace.permissions.includes('sales.view')" :key="store?.id||'none'" :store="store" :business="workspace.business" @open-order="openSavedOrder" />
                    <ProductsHubView v-else-if="(page === 'catalog' || page === 'inventory') && (workspace.permissions.includes('catalog.view') || workspace.permissions.includes('inventory.view'))" :business="workspace.business" :permissions="workspace.permissions" :actor-id="session.user.id" :store="store" :warehouses="warehouses" :initial-tab="page === 'inventory' ? 'inventory' : 'catalog'" />
                    <CustomerLinkView v-else-if="page === 'customer-links' && workspace.permissions.includes('customers.view') && workspace.permissions.includes('sales.view') && workspace.permissions.includes('sales.create')" :key="store?.id || 'none'" :actor-id="session.user.id" :business="workspace.business" :store="store" />
                    <CustomersView v-else-if="page === 'customers' && workspace.permissions.includes('customers.view')" :key="store?.id || 'none'" :actor-id="session.user.id" :business="workspace.business" :store="store" :permissions="workspace.permissions" @open-order="openSavedOrder" />
                    <EmployeesView v-else-if="page==='employees'&&workspace.permissions.includes('members.manage')" :key="store?.id||'none'" :store="store" :stores="workspace.stores" :actor-id="session.user.id" @context-locked="employeeContextLocked=$event" />
                    <WeeklyAccountsView v-else-if="page==='weekly'&&workspace.permissions.includes('sales.view')&&workspace.permissions.includes('customers.view')" :key="store?.id||'none'" :actor-id="session.user.id" :store="store" :business="workspace.business" :permissions="workspace.permissions" @context-locked="weeklyContextLocked=$event" @open-order="openSavedOrder" />
                    <ReceivablesView v-else-if="page === 'receivables' && workspace.permissions.includes('sales.view') && workspace.permissions.includes('customers.view')" :key="store?.id || 'none'" :store="store" :permissions="workspace.permissions" @open-order="openSavedOrder" />
                    <InvoicesView v-else-if="page === 'invoices' && workspace.permissions.includes('invoices.view')" :key="store?.id || 'none'" :store="store" @open-order="openSavedOrder" />
                    <BasketBoard v-else-if="page==='baskets'&&workspace.permissions.includes('sales.view')" ref="basketBoard" :key="store?.id||'none'" :actor-id="session.user.id" :active="page==='baskets'" :business="workspace.business" :store="store" :warehouses="warehouses" :permissions="workspace.permissions" @context-locked="basketContextLocked=$event" />
                    <WhatsAppInboxView v-else-if="page==='whatsapp-inbox'&&workspace.permissions.includes('sales.view')" :key="store?.id||'none'" :store="store" :permissions="workspace.permissions" :initial-conversation-id="waTargetConversationId" @open-order="openSavedOrder" @open-settings="openWhatsAppSettings" @read-updated="checkWhatsAppUnread(true)" />
                    <TeamView v-else-if="page === 'team'" :business="workspace.business" @access-changed="refreshAccess" />
                    <BusinessSettings v-else-if="page !== 'sales'" :key="page" :business="workspace.business" :stores="workspace.stores" :warehouses="warehouses" :store="store" :permissions="workspace.permissions" :page="page" @changed="reload" />
                    <KeepAlive :max="5"><SalesWorkspace v-if="salesVisited && workspace.permissions.includes('sales.view')" ref="salesWorkspace" v-show="page === 'sales'" :key="store?.id || 'none'" :actor-id="session.user.id" :active="page === 'sales'" :business="workspace.business" :store="store" :warehouses="warehouses" :permissions="workspace.permissions" @context-locked="salesContextLocked = $event" @title-change="salesTitle = $event" @open-apartados="openSavedOrder" /></KeepAlive>
                    <PaymentMethodSettings v-if="page==='settings'&&workspace.permissions.includes('business.manage')" :store="store || null" />
                    <InvoiceSettings v-if="page==='settings'&&workspace.permissions.includes('business.manage')" :store="store || null" />
                    <ReservationSettings v-if="page==='settings'&&workspace.permissions.includes('business.manage')" />
                    <WeeklySettings v-if="page==='settings'&&workspace.permissions.includes('business.manage')" />
                    <WhatsAppSettings v-if="page==='settings'&&workspace.permissions.includes('business.manage')" :store="store" />
                    <WhatsAppAutomations v-if="page==='settings'&&workspace.permissions.includes('business.manage')" :store="store" />
                    <footer class="content-footer"><span>BShop · {{ activeBrandName }}.</span></footer>
                </template>
            </main>
        </div>
    </div>
</template>
<style scoped>
.wa-nav-badge {
    margin-left: auto;
    background: #dc2626;
    color: #fff;
    font-size: 0.72rem;
    font-weight: 800;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.wa-topbar-pill {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}
.wa-live-toast {
    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 90;
    max-width: min(400px, calc(100vw - 32px));
    background: #17152c;
    color: #fff;
    border-radius: 14px;
    padding: 12px 14px;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.28);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.wa-live-toast-body {
    cursor: pointer;
    min-width: 0;
    flex: 1;
}
.wa-live-toast-body strong {
    display: block;
    font-size: 0.9rem;
}
.wa-live-toast-body p {
    margin: 3px 0 0;
    font-size: 0.82rem;
    color: #d1d5db;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.wa-live-toast-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}
.wa-live-toast-actions .button {
    min-height: 34px;
    padding: 4px 12px;
    font-size: 0.82rem;
    white-space: nowrap;
}
</style>

