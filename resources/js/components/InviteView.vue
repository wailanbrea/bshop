<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { api, csrf, errorMessage } from '../api';
import type { Session } from '../types';
import AuthView from './AuthView.vue';

const props = defineProps<{ token: string; session: Session | null }>();
const emit = defineEmits<{ accepted: []; authenticated: []; logout: [] }>();
const invitation = ref<{ company_name: string; recipient: string; expires_at: string } | null>(null);
const loading = ref(true); const busy = ref(false); const login = ref(false); const error = ref('');
const form = reactive({ email: props.session?.user.email || '', name: '', password: '', password_confirmation: '' });
async function accept() {
    busy.value = true; error.value = '';
    try {
        await csrf();
        const response = await api.post(`/invitations/${props.token}/accept`, props.session ? { email: props.session.user.email } : form);
        form.password = ''; form.password_confirmation = '';
        emit('accepted');
    } catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
onMounted(async () => {
    try { invitation.value = (await api.get(`/invitations/${props.token}`)).data.data; }
    catch (e) { error.value = errorMessage(e); }
    finally { loading.value = false; }
});
</script>
<template>
    <AuthView v-if="login && !session" @authenticated="login = false; emit('authenticated')" />
    <main v-else class="invitation-page">
        <a href="/" class="brand"><span class="brand-mark">b<span>·</span></span>BShop</a>
        <section class="panel invitation-card">
            <span class="eyebrow">EL EQUIPO DE TU NEGOCIO</span>
            <h1>{{ invitation ? `Únete a ${invitation.company_name}` : 'Invitación a BShop' }}</h1>
            <p v-if="loading" class="muted" role="status">Comprobando invitación…</p>
            <template v-else-if="invitation">
                <p class="muted">Acceso para {{ invitation.recipient }}. Tu rol y tus sucursales están definidos por el propietario.</p>
                <form class="form-stack" @submit.prevent="accept">
                    <template v-if="!session">
                        <label>Tu nombre<input v-model="form.name" required maxlength="160" autocomplete="name"></label>
                        <label>Correo invitado<input v-model="form.email" type="email" required maxlength="190" autocomplete="username"></label>
                        <label>Elige una contraseña<input v-model="form.password" type="password" required minlength="12" autocomplete="new-password"></label>
                        <small class="muted">Al menos 12 caracteres, letras y números.</small>
                        <label>Confirma la contraseña<input v-model="form.password_confirmation" type="password" required minlength="12" autocomplete="new-password"></label>
                    </template>
                    <p v-else class="notice">Estás conectado como {{ session.user.email }}. Debe ser el correo invitado.</p>
                    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
                    <button class="button primary" :disabled="busy">{{ busy ? 'Confirmando…' : session ? 'Aceptar invitación →' : 'Crear mi acceso →' }}</button>
                    <button v-if="!session" type="button" class="text-button" @click="login = true">Ya tengo acceso · Iniciar sesión</button>
                    <button v-else type="button" class="text-button" @click="emit('logout')">Usar otra cuenta</button>
                </form>
            </template>
            <template v-else><p class="notice error" role="alert">{{ error }}</p><a href="/" class="button secondary">Ir a BShop</a></template>
        </section>
        <p class="muted">Tu usuario forma parte del negocio y sus sucursales autorizadas.</p>
    </main>
</template>
