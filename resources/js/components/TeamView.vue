<script setup lang="ts">
import { nextTick, onMounted, reactive, ref } from 'vue';
import { api, errorMessage } from '../api';
import type { Team, TeamMember, TeamInvitation, Business } from '../types';
import AppDialog from './AppDialog.vue';

const props = defineProps<{ business: Business }>();
const emit = defineEmits<{ accessChanged: [] }>();
const team = ref<Team | null>(null); const loading = ref(true); const busy = ref(false);
const error = ref(''); const notice = ref(''); const link = ref(''); const copied = ref(false);
const errorNotice = ref<HTMLElement>();
const dialogOpener = ref<HTMLElement | null>(null);
const modal = ref<'invite' | 'edit' | null>(null); const memberId = ref<number | null>(null);
const revokeTarget = ref<TeamInvitation | null>(null);
const form = reactive({ email: '', role_id: 0, store_ids: [] as number[], status: 'active' });
const base = '/business';
async function load() {
    loading.value = true;
    try { team.value = (await api.get(`${base}/team`)).data.data; }
    catch (e) { error.value = errorMessage(e); }
    finally { loading.value = false; }
}
function open(member?: TeamMember, event?: Event) {
    dialogOpener.value = event?.currentTarget as HTMLElement || document.activeElement as HTMLElement;
    error.value = ''; notice.value = ''; memberId.value = member?.id || null;
    form.email = member?.email || '';
    form.role_id = member?.role_ids[0] || team.value?.roles.find(r => r.code === 'seller')?.id || 0;
    form.store_ids = [...(member?.store_ids || [])]; form.status = member?.status || 'active';
    modal.value = member ? 'edit' : 'invite';
}
function close() { modal.value = null; }
async function save() {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        if (modal.value === 'invite') {
            link.value = (await api.post(`${base}/invitations`, { email: form.email, role_id: form.role_id, store_ids: form.store_ids })).data.data.invitation_url;
            copied.value = false; notice.value = 'Invitación creada. Copia el enlace y compártelo directamente con esa persona.';
            close(); await load();
        } else {
            await api.patch(`${base}/users/${memberId.value}`, { role_id: form.role_id, store_ids: form.store_ids, status: form.status });
            close(); notice.value = 'Acceso actualizado correctamente.'; emit('accessChanged'); await load();
        }
    } catch (e) { error.value = errorMessage(e); await nextTick(); errorNotice.value?.focus(); }
    finally { busy.value = false; }
}
async function revoke(invitation: TeamInvitation, event?: Event) {
    if (busy.value) return;
    dialogOpener.value = event?.currentTarget as HTMLElement || document.activeElement as HTMLElement;
    revokeTarget.value = invitation;
}
async function confirmRevoke() {
    if (busy.value || !revokeTarget.value) return;
    busy.value = true; error.value = '';
    try { await api.post(`${base}/invitations/${revokeTarget.value.id}/revoke`); revokeTarget.value = null; link.value = ''; notice.value = 'Invitación revocada.'; await load(); }
    catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
async function copy() {
    try { await navigator.clipboard.writeText(link.value); copied.value = true; }
    catch { document.querySelector<HTMLInputElement>('#invitation-link')?.select(); }
}
function expiry(date: string) { return new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.business.timezone }).format(new Date(date)); }
onMounted(load);
</script>
<template>
    <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
    <p v-if="error && !modal" class="notice error" role="alert">{{ error }}</p>
    <section v-if="link" class="panel invite-result"><label for="invitation-link">Enlace privado de invitación · válido durante 72 horas</label><div class="invite-link-row"><input id="invitation-link" :value="link" readonly @focus="($event.target as HTMLInputElement).select()"><button class="button primary" @click="copy">{{ copied ? 'Copiado ✓' : 'Copiar enlace' }}</button><button class="icon-button" @click="link = ''" aria-label="Ocultar enlace de invitación">×</button></div><p class="muted">Sólo puede aceptarlo el correo invitado. El enlace se muestra al crearlo y no se guarda en el navegador.</p></section>
    <section class="panel"><div class="panel-heading"><div><span class="eyebrow">PERSONAS Y PERMISOS</span><h2>El equipo de {{ business.name }}</h2><p class="muted">Cada persona tiene un rol y sucursales autorizadas.</p></div><button class="button primary" @click="open(undefined, $event)" :disabled="loading || busy || !team?.stores.length">+ Invitar persona</button></div>
        <p v-if="loading" class="empty" role="status">Cargando el equipo…</p>
        <div v-else-if="team" class="record-list"><article v-for="member in team.members" :key="member.id" class="record team-record"><span class="avatar">{{ member.name.slice(0, 1) }}</span><div class="record-main"><strong>{{ member.name }}</strong><p>{{ member.email }}</p><p>{{ member.role_labels.join(', ') }} · {{ member.store_ids.length }} sucursales autorizadas</p></div><span class="badge" :class="{ inactive: member.status !== 'active' || !member.user_active }">{{ !member.user_active ? 'Cuenta inactiva' : member.status === 'active' ? 'Activo' : 'Suspendido' }}</span><button class="button secondary" @click="open(member, $event)" :disabled="busy || !member.can_manage" :title="member.can_manage ? '' : 'Tiene accesos fuera de tus sucursales autorizadas'">Gestionar<span class="sr-only"> {{ member.name }}</span></button></article></div>
        <p class="muted team-footnote">El propietario administra el equipo. Los administradores gestionan ubicaciones y los vendedores sólo consultan los módulos disponibles en esta etapa. No es posible retirar al último propietario activo.</p>
    </section>
    <section v-if="team?.invitations.length" class="panel pending-invites"><div class="panel-heading"><div><span class="eyebrow">ESPERANDO CONFIRMACIÓN</span><h2>Invitaciones pendientes</h2></div><span class="badge">{{ team.invitations.length }} vigentes</span></div><article v-for="invitation in team.invitations" :key="invitation.id" class="record"><span class="record-icon">↗</span><div class="record-main"><strong>{{ invitation.email }}</strong><p>{{ team.roles.find(r => r.id === invitation.role_id)?.label }} · Caduca {{ expiry(invitation.expires_at) }}</p></div><button class="button secondary" @click="revoke(invitation, $event)" :disabled="busy">Revocar<span class="sr-only"> {{ invitation.email }}</span></button></article></section>
    <AppDialog :open="modal !== null" :return-focus="dialogOpener" :title="modal === 'invite' ? 'Invitar al equipo' : 'Gestionar acceso'" :busy="busy" @close="close">
        <form id="team-access-form" class="form-stack" :aria-describedby="error ? 'team-access-error' : undefined" @submit.prevent="save">
            <fieldset class="form-fields" :disabled="busy">
                <label v-if="modal === 'invite'">Correo de la persona<input v-model="form.email" type="email" inputmode="email" required maxlength="190" autocomplete="off" autocapitalize="none" :spellcheck="false" enterkeyhint="next"></label>
                <p v-else class="muted">{{ form.email }} · La identidad de la cuenta no cambia al editar su acceso.</p>
                <label>Rol<select v-model.number="form.role_id" required><option v-for="role in team?.roles" :key="role.id" :value="role.id">{{ role.label }}</option></select></label>
                <fieldset class="store-checks"><legend>Sucursales autorizadas</legend>
                    <label v-for="branch in team?.stores" :key="branch.id" class="check-label"><input v-model="form.store_ids" :value="branch.id" type="checkbox">{{ branch.name }}{{ branch.is_active ? '': ' · Inactiva' }}</label>
                </fieldset>
                <label v-if="modal === 'edit'">Estado del acceso<select v-model="form.status"><option value="active">Activo</option><option value="suspended">Suspendido</option></select></label>
                <p class="notice">{{ modal === 'invite' ? 'Se generará un enlace privado para que la persona configure su acceso al negocio.' : 'Los cambios se aplican en las siguientes peticiones y trabajos del usuario.' }}</p>
            </fieldset>
            <p v-if="error" id="team-access-error" ref="errorNotice" tabindex="-1" class="notice error" role="alert">{{ error }}</p>
        </form>
        <template #footer>
            <button type="button" class="button secondary" @click="close" :disabled="busy">Cancelar</button>
            <button type="submit" form="team-access-form" class="button primary" :disabled="busy || !form.store_ids.length">{{ busy ? 'Guardando…' : modal === 'invite' ? 'Generar invitación' : 'Guardar acceso' }}</button>
        </template>
    </AppDialog>
    <AppDialog :open="revokeTarget !== null" :return-focus="dialogOpener" title="Revocar invitación" :busy="busy" @close="revokeTarget = null">
        <p class="muted">La invitación de <strong>{{ revokeTarget?.email }}</strong> dejará de funcionar.</p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <template #footer>
            <button type="button" class="button secondary" @click="revokeTarget = null" :disabled="busy">Cancelar</button>
            <button type="button" class="button primary" @click="confirmRevoke" :disabled="busy">{{ busy ? 'Revocando…' : 'Revocar invitación' }}</button>
        </template>
    </AppDialog>
</template>
