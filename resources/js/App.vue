<script setup lang="ts">
import { computed, defineAsyncComponent, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from './api';
import type { Session, Workspace, Warehouse } from './types';
import AuthView from './components/AuthView.vue';
const BusinessSettings = defineAsyncComponent(() => import('./components/BusinessSettings.vue'));
const TeamView = defineAsyncComponent(() => import('./components/TeamView.vue'));
const InviteView = defineAsyncComponent(() => import('./components/InviteView.vue'));
const CatalogView = defineAsyncComponent(() => import('./components/CatalogView.vue'));
import AppIcon from './components/AppIcon.vue';
const InventoryView = defineAsyncComponent(() => import('./components/InventoryView.vue'));
import SalesWorkspace from './components/SalesWorkspace.vue';
const CustomersView = defineAsyncComponent(() => import('./components/CustomersView.vue'));
const CustomerLinkView = defineAsyncComponent(() => import('./components/CustomerLinkView.vue'));
const WeeklyAccountsView = defineAsyncComponent(() => import('./components/WeeklyAccountsView.vue'));
const InvoicesView = defineAsyncComponent(() => import('./components/InvoicesView.vue'));
const ReceivablesView = defineAsyncComponent(() => import('./components/ReceivablesView.vue'));
import BasketBoard from './components/BasketBoard.vue';
const WhatsAppSettings = defineAsyncComponent(() => import('./components/WhatsAppSettings.vue'));
const ReservationSettings = defineAsyncComponent(() => import('./components/ReservationSettings.vue'));
const SalesControlView = defineAsyncComponent(() => import('./components/SalesControlView.vue'));
import {salesControlText} from './locales/es/salesControl';
import { useVisualViewport } from './composables/useVisualViewport';
import { lockPageScroll } from './lib/scrollLock';

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
const page = ref(location.pathname.includes('/vista-cliente') ? 'customer-links' : location.pathname.includes('/historial-ventas') ? 'history' : location.pathname.includes('/cuentas-semanales') ? 'weekly' : location.pathname.includes('/cxc') ? 'receivables' : location.pathname.includes('/apartados') ? 'baskets' : /\/ventas(\/|$)/.test(location.pathname) ? 'sales' : 'overview');
const weeklyContextLocked = ref(false);
const basketBoard = ref<InstanceType<typeof BasketBoard>>(); const basketContextLocked=ref(false);
const salesVisited = ref(page.value === 'sales'); const salesContextLocked = ref(false);
const salesTitle = ref(location.pathname.includes('/apartados/') ? 'Nuevo apartado' : 'Venta');
const salesWorkspace = ref<InstanceType<typeof SalesWorkspace>>();
const invitationToken = ref(location.pathname.startsWith('/join/') ? location.pathname.split('/').pop() || '' : '');
const nav = computed(() => [{ id: 'overview', label: 'Vista general', icon: 'home' }, ...(workspace.value?.permissions.includes('sales.view') && workspace.value?.permissions.includes('customers.view') ? [{ id: 'receivables', label: 'Cuentas por cobrar', icon: 'document' },{id:'weekly',label:'Cuentas semanales',icon:'document'}] : []), ...(workspace.value?.permissions.includes('sales.view') ? [{ id: 'sales', label: 'Ventas', icon: 'cart' },{id:'history',label:salesControlText.navigation,icon:'document'},{id:'baskets',label:'Apartados',icon:'bag'}] : []), ...(workspace.value?.permissions.includes('customers.view') && workspace.value?.permissions.includes('sales.view') && workspace.value?.permissions.includes('sales.create') ? [{ id: 'customer-links', label: 'Vista del cliente', icon: 'users' }] : []), ...(workspace.value?.permissions.includes('customers.view') ? [{ id: 'customers', label: 'Clientes', icon: 'users' }] : []), ...(workspace.value?.permissions.includes('catalog.view') ? [{ id: 'catalog', label: 'Catálogo', icon: 'box' }] : []), ...(workspace.value?.permissions.includes('inventory.view') ? [{ id: 'inventory', label: 'Inventario', icon: 'inventory' }] : []), ...(workspace.value?.permissions.includes('invoices.view') ? [{ id: 'invoices', label: 'Facturas', icon: 'document' }] : []), { id: 'stores', label: 'Sucursales', icon: 'store' }, { id: 'warehouses', label: 'Almacenes', icon: 'warehouse' }, ...(workspace.value?.permissions.includes('members.manage') ? [{ id: 'team', label: 'Equipo', icon: 'users' }] : []), { id: 'settings', label: 'Configuración', icon: 'settings' }]);
const store = computed(() => workspace.value?.stores.find(s => s.id === storeId.value));
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
        if (page.value === 'team' && !result.permissions.includes('members.manage')) page.value = 'overview';
        if (['sales','baskets','history'].includes(page.value) && !result.permissions.includes('sales.view')) page.value = 'overview';
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
function navigate(id: string) { page.value = id; if (id === 'sales') { salesVisited.value = true; history.replaceState({}, '', '/app/ventas'); } else if(id==='customer-links')history.replaceState({},'', '/app/vista-cliente');else if(id==='history')history.replaceState({},'', '/app/historial-ventas');else if(id==='weekly')history.replaceState({},'', '/app/cuentas-semanales');else if(id==='receivables')history.replaceState({},'', '/app/cxc');else if(id==='baskets')history.replaceState({},'', '/app/apartados');else if (!invitationToken.value) history.replaceState({}, '', '/'); menuOpen.value = false; window.scrollTo({ top: 0, behavior: 'auto' }); }
async function openSavedOrder(id?: number) { navigate('baskets'); await nextTick(); if(id)await basketBoard.value?.select(id); }
async function invitationAccepted() { invitationToken.value = ''; history.replaceState({}, '', '/'); await loadSession(); }
onMounted(loadSession);
onMounted(() => { drawerQuery.addEventListener('change', syncLayout); phoneQuery.addEventListener('change', syncLayout); });
onUnmounted(() => { unlockMenu?.(); drawerQuery.removeEventListener('change', syncLayout); phoneQuery.removeEventListener('change', syncLayout); });
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
            <a href="/" class="brand" :aria-label="`${workspace?.business.name || session.business.name} inicio`"><span class="brand-mark" aria-hidden="true">{{ (workspace?.business.name || session.business.name).slice(0, 1) }}</span><span class="brand-word"><strong>{{ workspace?.business.name || session.business.name }}</strong><small>Gestión de tienda</small></span></a>
            <div class="workspace-switch"><span class="eyebrow">TU NEGOCIO</span><strong class="business-name">{{ workspace?.business.name || session.business.name }}</strong><span class="workspace-subtitle">Tu negocio y sus sucursales</span></div>
            <span class="nav-caption">PRINCIPAL</span><nav aria-label="Navegación principal"><button v-for="item in nav" :key="item.id" class="nav-item" :class="{ active: page === item.id }" :aria-label="item.label" :aria-current="page === item.id ? 'page' : undefined" @click="navigate(item.id)"><span><AppIcon :name="item.icon" /></span><span class="nav-label">{{ item.label }}</span><span v-if="page === item.id" class="nav-active-dot"></span></button></nav>
            <div class="sidebar-bottom"><div class="support-card"><span><AppIcon name="store" /></span><strong>Mi tienda</strong><p>{{ workspace?.business.name || session.business.name }}</p></div><div class="user-block"><span class="avatar">{{ session.user.name.slice(0, 1) }}</span><div><strong>{{ session.user.name }}</strong><small>{{ roles }}</small></div><button class="icon-button" @click="logout" aria-label="Cerrar sesión" title="Cerrar sesión">↪</button></div></div>
        </aside>
        <div class="main-shell" :inert="menuOpen ? true : undefined">
            <header class="topbar">
                <div class="breadcrumb"><button ref="menuButton" type="button" class="icon-button menu-toggle" @click="menuOpen = !menuOpen" aria-label="Abrir menú" aria-controls="main-navigation" :aria-expanded="menuOpen">☰</button><div v-if="page === 'sales'" class="sales-header-title"><h1>{{ salesTitle }}</h1><p>Ventas y apartados de tu negocio</p></div><template v-else><span class="breadcrumb-parent">Mi negocio</span><span class="breadcrumb-slash">/</span><strong>{{ title }}</strong></template></div>
                <label v-if="workspace" class="branch-picker"><span>SUCURSAL ACTIVA</span><select v-model="storeId" :disabled="(page === 'sales' && salesContextLocked)||(page==='baskets'&&basketContextLocked)||(page==='weekly'&&weeklyContextLocked)" @change="loadWarehouses"><option v-for="branch in workspace.stores" :key="branch.id" :value="branch.id">{{ branch.name }}{{ branch.is_active ? '' : ' · Inactiva' }}</option><option v-if="!workspace.stores.length" value="">Sin acceso a sucursales</option></select></label>
                <div class="topbar-right"><span class="secure-label"><span class="status-dot"></span> Sesión segura</span><span class="avatar small">{{ session.user.name.slice(0, 1) }}</span><div class="topbar-profile"><strong>{{ session.user.name }}</strong><small>{{ roles }}</small></div></div>
            </header>
            <main class="main-content" :class="{ 'sales-page': page === 'sales' }">
                <p v-if="error" class="notice error" role="alert">{{ error }} <button class="text-button" @click="loadWorkspace">Reintentar</button></p>
                <div v-if="switching" class="skeleton-grid" aria-label="Cargando negocio" role="status"><div></div><div></div><div></div></div>
                <template v-else-if="workspace">
                    <div v-if="!['sales','baskets','history'].includes(page) && !(page==='overview'&&workspace.permissions.includes('sales.view'))" class="page-heading"><div><span class="eyebrow">{{ date }}</span><h1>{{ page === 'overview' ? `Vista general` : title }}</h1><p>{{ page === 'overview' ? `Consulta la operación de ${workspace.business.name}.` : `Administra la operación de ${workspace.business.name}.` }}</p></div></div>
                    <template v-if="page === 'overview'">
                        <SalesControlView v-if="workspace.permissions.includes('sales.view')" :key="store?.id||'none'" :store="store" :business="workspace.business" compact @history="navigate('history')" @open-order="openSavedOrder" />
                        <template v-else>
                        <section class="welcome-banner"><div><span class="pill light-pill">{{ workspace.business.name }}</span><h2>Tu tienda,<br>en un solo lugar.</h2><p>{{ store?.name || 'Selecciona una sucursal para operar.' }}</p><button v-if="workspace.permissions.includes('sales.view')" class="button cream" @click="navigate('baskets')">Ver apartados <span>→</span></button><button v-else class="button cream" @click="navigate('stores')">Ver sucursales <span>→</span></button></div><div class="banner-art" aria-hidden="true"><div class="art-orbit"></div><div class="art-shop"><span class="art-awning"></span><span class="art-roof"></span><div class="art-window"></div><div class="art-door"></div></div><span class="art-spark">✦</span></div></section>
                        <section class="stats-grid" aria-label="Resumen de configuración"><article class="stat-card"><div class="stat-top"><span>Sucursales activas</span><span class="stat-icon"><AppIcon name="store" /></span></div><strong>{{ activeStores.toString().padStart(2, '0') }}</strong><p>De {{ workspace.stores.length }} sucursales autorizadas</p></article><article class="stat-card"><div class="stat-top"><span>Almacenes activos</span><span class="stat-icon peach"><AppIcon name="warehouse" /></span></div><strong>{{ activeWarehouses.toString().padStart(2, '0') }}</strong><p>En {{ store?.name || 'la sucursal seleccionada' }}</p></article><article class="stat-card"><div class="stat-top"><span>Moneda del negocio</span><span class="stat-icon lavender">$</span></div><strong>{{ workspace.business.default_currency }}</strong><p>Zona horaria: {{ workspace.business.timezone }}</p></article></section>
                        <div class="overview-bottom"><section class="panel setup-panel"><div class="panel-heading"><div><h2>Accesos rápidos</h2></div></div><div class="setup-step"><span class="step-icon done">V</span><div><strong>Datos del negocio</strong><p>Nombre comercial y zona horaria.</p></div><button class="text-button" @click="navigate('settings')">Ver datos →</button></div><div class="setup-step"><span class="step-icon" :class="{ done: activeStores > 0 }">{{ activeStores ? 'V' : '2' }}</span><div><strong>Almacenes</strong><p>Ubicaciones de la sucursal seleccionada.</p></div><button class="text-button" @click="navigate('warehouses')">Revisar →</button></div><div v-if="workspace.permissions.includes('catalog.view')" class="setup-step"><span class="step-icon"><AppIcon name="box" /></span><div><strong>Catálogo</strong><p>Productos, variantes, precios e imágenes.</p></div><button class="text-button" @click="navigate('catalog')">Ver catálogo →</button></div></section><section class="panel context-card"><span class="eyebrow">ESTÁS TRABAJANDO EN</span><span class="context-symbol"><AppIcon name="store" /></span><h2>{{ store?.name || 'Selecciona una sucursal' }}</h2><p>{{ store?.address || 'Añade la dirección y los datos de contacto de esta ubicación.' }}</p><div><span class="badge">{{ store?.code || 'Sin sucursal' }}</span><span v-if="store" class="muted small-text">{{ store.is_active ? 'Activa' : 'Inactiva' }}</span></div><button class="button secondary full" @click="navigate('stores')">Ver sucursales →</button></section></div>
                        </template>
                    </template>
                    <SalesControlView v-else-if="page==='history'&&workspace.permissions.includes('sales.view')" :key="store?.id||'none'" :store="store" :business="workspace.business" @open-order="openSavedOrder" />
                    <CatalogView v-else-if="page === 'catalog' && workspace.permissions.includes('catalog.view')" :business="workspace.business" :permissions="workspace.permissions" :actor-id="session.user.id" :store="store" :warehouses="warehouses" />
                    <InventoryView v-else-if="page === 'inventory' && workspace.permissions.includes('inventory.view')" :key="store?.id || 'none'" :store="store" :warehouses="warehouses" :permissions="workspace.permissions" />
                    <CustomerLinkView v-else-if="page === 'customer-links' && workspace.permissions.includes('customers.view') && workspace.permissions.includes('sales.view') && workspace.permissions.includes('sales.create')" :key="store?.id || 'none'" :actor-id="session.user.id" :business="workspace.business" :store="store" />
                    <CustomersView v-else-if="page === 'customers' && workspace.permissions.includes('customers.view')" :key="store?.id || 'none'" :actor-id="session.user.id" :business="workspace.business" :store="store" :permissions="workspace.permissions" @open-order="openSavedOrder" />
                    <WeeklyAccountsView v-else-if="page==='weekly'&&workspace.permissions.includes('sales.view')&&workspace.permissions.includes('customers.view')" :key="store?.id||'none'" :actor-id="session.user.id" :store="store" :business="workspace.business" :permissions="workspace.permissions" @context-locked="weeklyContextLocked=$event" @open-order="openSavedOrder" />
                    <ReceivablesView v-else-if="page === 'receivables' && workspace.permissions.includes('sales.view') && workspace.permissions.includes('customers.view')" :key="store?.id || 'none'" :store="store" :permissions="workspace.permissions" @open-order="openSavedOrder" />
                    <InvoicesView v-else-if="page === 'invoices' && workspace.permissions.includes('invoices.view')" :key="store?.id || 'none'" :store="store" @open-order="openSavedOrder" />
                    <BasketBoard v-else-if="page==='baskets'&&workspace.permissions.includes('sales.view')" ref="basketBoard" :key="store?.id||'none'" :actor-id="session.user.id" :active="page==='baskets'" :business="workspace.business" :store="store" :warehouses="warehouses" :permissions="workspace.permissions" @context-locked="basketContextLocked=$event" />
                    <TeamView v-else-if="page === 'team'" :business="workspace.business" @access-changed="refreshAccess" />
                    <BusinessSettings v-else-if="page !== 'sales'" :key="page" :business="workspace.business" :stores="workspace.stores" :warehouses="warehouses" :store="store" :permissions="workspace.permissions" :page="page" @changed="reload" />
                    <KeepAlive :max="5"><SalesWorkspace v-if="salesVisited && workspace.permissions.includes('sales.view')" ref="salesWorkspace" v-show="page === 'sales'" :key="store?.id || 'none'" :actor-id="session.user.id" :active="page === 'sales'" :business="workspace.business" :store="store" :warehouses="warehouses" :permissions="workspace.permissions" @context-locked="salesContextLocked = $event" @title-change="salesTitle = $event" @open-apartados="openSavedOrder" /></KeepAlive>
                    <ReservationSettings v-if="page==='settings'&&workspace.permissions.includes('business.manage')" />
                    <WhatsAppSettings v-if="page==='settings'&&workspace.permissions.includes('business.manage')" />
                    <footer class="content-footer"><span>BShop · {{ workspace.business.name }} y sus sucursales.</span></footer>
                </template>
            </main>
        </div>
    </div>
</template>

