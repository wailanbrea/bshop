<script setup lang="ts">
import { reactive, ref } from 'vue';
import { api, csrf, errorMessage } from '../api';

const emit = defineEmits<{ authenticated: [] }>();
const resetToken = location.pathname.startsWith('/reset-password/') ? decodeURIComponent(location.pathname.split('/').pop() || '') : '';
const mode = ref<'login' | 'forgot' | 'reset'>(resetToken ? 'reset' : 'login');
const form = reactive({ email: new URLSearchParams(location.search).get('email') || '', password: '', password_confirmation: '' });
const busy = ref(false);
const error = ref('');
const message = ref('');
async function submit() {
    busy.value = true; error.value = ''; message.value = '';
    try {
        await csrf();
        if (mode.value === 'login') {
            await api.post('/login', { email: form.email, password: form.password });
            form.password = ''; emit('authenticated');
        } else if (mode.value === 'forgot') {
            message.value = (await api.post('/forgot-password', { email: form.email })).data.message;
        } else {
            message.value = (await api.post('/reset-password', { ...form, token: resetToken })).data.message;
            mode.value = 'login'; form.password = ''; form.password_confirmation = '';
            history.replaceState({}, '', '/login');
        }
    } catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
</script>

<template>
    <main class="auth-layout">
        <section class="auth-story">
            <a class="brand light" href="/" aria-label="BShop inicio"><span class="brand-mark">b<span>·</span></span>BShop<span class="brand-tag">BUSINESS, SIMPLIFIED</span></a>
            <div class="story-copy"><span class="eyebrow light-text">UN ESPACIO PARA CRECER</span><h1>Tu negocio. <br>Más claro. <br><span>Más tuyo.</span></h1><p>El punto de encuentro entre tu equipo, tus sucursales y todo lo que viene para tu tienda.</p></div>
            <div class="story-card"><span class="round-icon">↗</span><div><strong>Tu negocio y tus sucursales</strong><p>Un acceso seguro. Una operación en orden.</p></div></div>
            <footer>BShop <span>Hecho para negocios que avanzan.</span></footer>
        </section>
        <section class="auth-form-panel">
            <div class="auth-form-wrap"><span class="pill"><span class="status-dot"></span> TU ESPACIO DE TRABAJO</span>
                <h2>{{ mode === 'login' ? 'Qué bueno verte.' : mode === 'forgot' ? 'Recupera tu acceso.' : 'Una nueva contraseña.' }}</h2>
                <p class="muted">{{ mode === 'login' ? 'Inicia sesión para continuar con tu negocio.' : mode === 'forgot' ? 'Te enviaremos un enlace al correo de tu cuenta.' : 'Usa al menos 12 caracteres, letras y números.' }}</p>
                <form @submit.prevent="submit" class="form-stack">
                    <label>Correo electrónico<input v-model="form.email" type="email" autocomplete="username" required placeholder="tu@empresa.com" :disabled="busy"></label>
                    <label v-if="mode !== 'forgot'">Contraseña<input v-model="form.password" type="password" :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" :minlength="mode === 'reset' ? 12 : undefined" required placeholder="Escribe tu contraseña" :disabled="busy"></label>
                    <label v-if="mode === 'reset'">Confirmar contraseña<input v-model="form.password_confirmation" type="password" autocomplete="new-password" required :disabled="busy"></label>
                    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
                    <p v-if="message" class="notice success" role="status">{{ message }}</p>
                    <button type="submit" class="button primary full" :disabled="busy">{{ busy ? 'Un momento…' : mode === 'login' ? 'Entrar a mi negocio →' : mode === 'forgot' ? 'Enviar instrucciones →' : 'Guardar contraseña →' }}</button>
                </form>
                <button class="text-button recovery" @click="mode = mode === 'login' ? 'forgot' : 'login'; error = ''; message = ''" :disabled="busy">{{ mode === 'login' ? '¿Olvidaste tu contraseña?' : 'Volver al inicio de sesión' }}</button>
                <div class="auth-note"><span>◇</span><p>Tu información pertenece a tu empresa.<br>El acceso se verifica en cada operación.</p></div>
            </div>
            <footer class="auth-bottom">BShop · Gestión de tu negocio <span>© {{ new Date().getFullYear() }} BSolutions.Dev</span></footer>
        </section>
    </main>
</template>
