<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Store } from '../types';
const props = defineProps<{ store?: Store }>();
const busy = ref(false);
const error = ref('');
const notice = ref('');
const status = ref<any>(null);
const form = reactive({
    enabled: false,
    phone_number_id: '',
    template: '',
    language: 'es',
    graph_version: 'v23.0',
    body_fields: 'customer_name,invoice_number,total',
    access_token: '',
    app_secret: '',
    verify_token: '',
});
function apply(data: any) {
    status.value = data;
    Object.assign(form, {
        enabled: data.enabled,
        phone_number_id: data.phone_number_id || '',
        template: data.template || '',
        language: data.language || 'es',
        graph_version: data.graph_version || 'v23.0',
        body_fields: (data.body_fields || []).join(','),
        access_token: '',
        app_secret: '',
        verify_token: '',
    });
}
async function load() {
    error.value = '';
    try {
        apply((await api.get('/business/integrations/whatsapp', { params: props.store?.id ? { store_id: props.store.id } : undefined })).data.data);
    } catch (e) {
        error.value = errorMessage(e);
    }
}
onMounted(load);
watch(() => props.store?.id, () => load());
async function save() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        apply(
            (
                await api.patch('/business/integrations/whatsapp', {
                    ...form,
                    ...(props.store?.id ? { store_id: props.store.id } : {}),
                    body_fields: form.body_fields
                        .split(',')
                        .map((s) => s.trim())
                        .filter(Boolean),
                })
            ).data.data,
        );
        notice.value = `Configuración de WhatsApp guardada para ${props.store?.name || 'la tienda'}. Los secretos quedan cifrados y aislados.`;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <section class="panel whatsapp-settings" aria-label="Configuración opcional de WhatsApp">
        <h2>WhatsApp · Opcional</h2>
        <p class="muted">
            Al habilitar la integración oficial, el botón del apartado enviará el PDF sin salir de la
            aplicación. Desactivado, la facturación sigue funcionando.
        </p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <p v-if="status" class="notice">{{ status.reason }}</p>
        <form class="form-stack" @submit.prevent="save">
            <fieldset class="form-fields" :disabled="busy">
                <label class="check-label"
                    ><input v-model="form.enabled" type="checkbox" /> Habilitar WhatsApp automático</label
                >
                <div class="form-grid">
                    <label
                        >ID del número de WhatsApp<input
                            v-model="form.phone_number_id"
                            inputmode="numeric"
                            autocomplete="off" /></label
                    ><label
                        >Versión de Graph API<input v-model="form.graph_version" required autocomplete="off" /></label
                    ><label
                        >Plantilla de factura aprobada<input
                            v-model="form.template"
                            placeholder="factura_cliente"
                            autocomplete="off" /></label
                    ><label>Idioma de plantilla<input v-model="form.language" required placeholder="es" /></label>
                </div>
                <label
                    >Variables del cuerpo, en orden<input
                        v-model="form.body_fields"
                        placeholder="customer_name,invoice_number,total"
                /></label>
                <p class="muted">
                    Campos admitidos: customer_name, invoice_number, total, business_name. La plantilla debe tener
                    encabezado de documento PDF y las variables configuradas.
                </p>
                <label
                    >Token de acceso {{ status?.has_access_token ? '(guardado; dejar vacío para conservar)' : ''
                    }}<input v-model="form.access_token" type="password" autocomplete="new-password" /></label
                ><label
                    >App Secret {{ status?.has_app_secret ? '(guardado)' : ''
                    }}<input v-model="form.app_secret" type="password" autocomplete="new-password" /></label
                ><label
                    >Token de verificación de webhook {{ status?.has_verify_token ? '(guardado)' : ''
                    }}<input v-model="form.verify_token" type="password" minlength="16" autocomplete="new-password"
                /></label>
                <p v-if="status?.webhook_url" class="muted">Webhook: {{ status.webhook_url }}</p>
                <p class="muted">
                    Las claves se guardan cifradas y no vuelven a mostrarse. Habilita el número y la plantilla en Meta y
                    registra este webhook HTTPS para confirmar envío/entrega.
                </p>
            </fieldset>
            <button type="submit" class="button primary" :disabled="busy">Guardar configuración de WhatsApp</button>
        </form>
    </section>
</template>
