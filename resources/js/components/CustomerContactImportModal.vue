<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import AppDialog from './AppDialog.vue';

type ParsedContact = {
    id: string;
    full_name: string;
    phone: string;
    email: string;
    selected: boolean;
};

const props = defineProps<{
    open: boolean;
    root: string;
    opener: HTMLElement | null;
}>();

const emit = defineEmits<{
    close: [];
    imported: [number];
}>();

const activeTab = ref<'phone' | 'file' | 'paste'>('phone');
const defaultCountryCode = ref('+1');
const pasteText = ref('');
const contacts = ref<ParsedContact[]>([]);
const busy = ref(false);
const error = ref('');
const notice = ref('');

const supportsContactPicker = computed(() => typeof navigator !== 'undefined' && 'contacts' in navigator && typeof (navigator as any).contacts?.select === 'function');
const selectedContacts = computed(() => contacts.value.filter(c => c.selected));

function normalizeDisplayPhone(raw: string): string {
    const trimmed = raw.trim();
    if (!trimmed) return '';
    const hasPlus = trimmed.startsWith('+');
    const digits = trimmed.replace(/\D+/g, '');
    if (digits.length < 7) return trimmed;
    if (hasPlus) return `+${digits}`;
    if (digits.length === 10 && defaultCountryCode.value === '+1') return `+1${digits}`;
    if (digits.length === 11 && digits.startsWith('1') && defaultCountryCode.value === '+1') return `+${digits}`;
    return `${defaultCountryCode.value}${digits}`;
}

function addParsed(rows: { full_name: string; phone: string; email?: string }[]) {
    const existingKeys = new Set(contacts.value.map(c => `${c.full_name.toLowerCase()}|${c.phone.replace(/\D+/g, '')}`));
    let added = 0;
    for (const r of rows) {
        const name = r.full_name.trim();
        const phone = normalizeDisplayPhone(r.phone || '');
        if (!name && !phone) continue;
        const finalName = name || phone;
        const key = `${finalName.toLowerCase()}|${phone.replace(/\D+/g, '')}`;
        if (existingKeys.has(key)) continue;
        existingKeys.add(key);
        contacts.value.push({
            id: crypto.randomUUID(),
            full_name: finalName,
            phone,
            email: (r.email || '').trim(),
            selected: true,
        });
        added++;
    }
    if (added > 0) {
        notice.value = `Se agregaron ${added} ${added === 1 ? 'contacto' : 'contactos'} a la vista previa.`;
    }
}

async function pickFromPhone() {
    error.value = '';
    notice.value = '';
    if (!supportsContactPicker.value) {
        error.value = 'Tu navegador no permite abrir la libreta directamente. Usa la pestaña «Archivo .vcf / .csv» o «Pegar lista».';
        return;
    }
    try {
        const picked = await (navigator as any).contacts.select(['name', 'tel', 'email'], { multiple: true });
        if (!Array.isArray(picked) || !picked.length) return;
        const parsed = picked.map((item: any) => ({
            full_name: Array.isArray(item.name) ? (item.name[0] || '') : String(item.name || ''),
            phone: Array.isArray(item.tel) ? (item.tel[0] || '') : String(item.tel || ''),
            email: Array.isArray(item.email) ? (item.email[0] || '') : String(item.email || ''),
        }));
        addParsed(parsed);
    } catch (e: any) {
        if (e?.name !== 'AbortError') {
            error.value = 'No se pudieron leer los contactos del dispositivo.';
        }
    }
}

function parseVcf(text: string): { full_name: string; phone: string; email?: string }[] {
    const cards = text.split(/BEGIN:VCARD/i).slice(1);
    const out: { full_name: string; phone: string; email?: string }[] = [];
    for (const rawCard of cards) {
        const unfolded = rawCard.replace(/\r?\n[ \t]/g, '');
        const lines = unfolded.split(/\r?\n/);
        let fn = '';
        let nName = '';
        let tel = '';
        let email = '';
        for (const line of lines) {
            if (/^FN[;:]/i.test(line)) {
                fn = line.slice(line.indexOf(':') + 1).trim();
            } else if (/^N[;:]/i.test(line) && !nName) {
                const parts = line.slice(line.indexOf(':') + 1).split(';').map(s => s.trim()).filter(Boolean);
                if (parts.length >= 2) nName = `${parts[1]} ${parts[0]}`.trim();
                else if (parts.length === 1) nName = parts[0];
            } else if (/^TEL[;:]/i.test(line) && !tel) {
                tel = line.slice(line.indexOf(':') + 1).trim();
            } else if (/^EMAIL[;:]/i.test(line) && !email) {
                email = line.slice(line.indexOf(':') + 1).trim();
            }
        }
        const name = fn || nName;
        if (name || tel) {
            out.push({ full_name: name || tel, phone: tel, email });
        }
    }
    return out;
}

function parseCsv(text: string): { full_name: string; phone: string; email?: string }[] {
    const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    if (!lines.length) return [];
    const sep = lines[0].includes(';') && !lines[0].includes(',') ? ';' : ',';
    const splitLine = (line: string) => line.split(sep).map(cell => cell.replace(/^"|"$/g, '').trim());
    const header = splitLine(lines[0]).map(h => h.toLowerCase());
    const nameIdx = header.findIndex(h => /name|nombre|cliente|contacto/.test(h));
    const phoneIdx = header.findIndex(h => /phone|tel|whats|cel|movil|móvil|numero|número/.test(h));
    const emailIdx = header.findIndex(h => /mail|correo/.test(h));
    const hasHeader = nameIdx >= 0 || phoneIdx >= 0;
    const rows = hasHeader ? lines.slice(1) : lines;
    const out: { full_name: string; phone: string; email?: string }[] = [];
    for (const line of rows) {
        const cols = splitLine(line);
        const name = cols[nameIdx >= 0 ? nameIdx : 0] || '';
        const phone = cols[phoneIdx >= 0 ? phoneIdx : 1] || '';
        const email = emailIdx >= 0 ? (cols[emailIdx] || '') : '';
        if (name || phone) out.push({ full_name: name || phone, phone, email });
    }
    return out;
}

async function onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    error.value = '';
    notice.value = '';
    try {
        const text = await file.text();
        const isVcf = /\.vcf$/i.test(file.name) || /BEGIN:VCARD/i.test(text);
        const parsed = isVcf ? parseVcf(text) : parseCsv(text);
        if (!parsed.length) {
            error.value = 'No se encontraron contactos válidos en ese archivo.';
            return;
        }
        addParsed(parsed);
    } catch {
        error.value = 'No se pudo leer el archivo seleccionado.';
    }
}

function parsePastedList() {
    error.value = '';
    notice.value = '';
    const lines = pasteText.value.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    if (!lines.length) {
        error.value = 'Pega al menos un contacto (ej. Daniel +18092374444).';
        return;
    }
    const parsed: { full_name: string; phone: string }[] = [];
    for (const line of lines) {
        const phoneMatch = line.match(/(\+?\d[\d\s().-]{6,}\d)/);
        const phone = phoneMatch ? phoneMatch[1].trim() : '';
        const name = (phoneMatch ? line.replace(phoneMatch[0], '') : line)
            .replace(/^[\s,;:\-–•]+|[\s,;:\-–•]+$/g, '')
            .trim();
        if (name || phone) {
            parsed.push({ full_name: name || phone, phone });
        }
    }
    if (!parsed.length) {
        error.value = 'No se detectaron contactos válidos en el texto pegado.';
        return;
    }
    addParsed(parsed);
    pasteText.value = '';
}

function toggleAll(checked: boolean) {
    for (const c of contacts.value) c.selected = checked;
}

async function submitImport() {
    if (busy.value || !selectedContacts.value.length) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const payload = {
            default_country_code: defaultCountryCode.value.trim() || '+1',
            contacts: selectedContacts.value.map(c => ({
                full_name: c.full_name,
                phone: c.phone || null,
                email: c.email || null,
            })),
        };
        const res = (await api.post(`${props.root}/customers/import`, payload)).data.data;
        const created = Number(res.created_count || 0);
        const skipped = Number(res.skipped_count || 0);
        notice.value = `Importación completada: ${created} nuevo(s), ${skipped} ya existente(s)/omitido(s).`;
        contacts.value = [];
        emit('imported', created);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        error.value = '';
        notice.value = '';
        contacts.value = [];
        pasteText.value = '';
        activeTab.value = supportsContactPicker.value ? 'phone' : 'file';
    }
});
</script>

<template>
    <AppDialog
        :open="open"
        title="Importar contactos desde WhatsApp"
        :busy="busy"
        :wide="true"
        :return-focus="opener"
        @close="emit('close')"
    >
        <div class="wa-imp-shell">
            <div class="wa-imp-tabs" role="tablist">
                <button type="button" class="button" :class="activeTab === 'phone' ? 'primary' : 'secondary'" @click="activeTab = 'phone'">
                    Desde el teléfono
                </button>
                <button type="button" class="button" :class="activeTab === 'file' ? 'primary' : 'secondary'" @click="activeTab = 'file'">
                    Archivo .vcf / .csv
                </button>
                <button type="button" class="button" :class="activeTab === 'paste' ? 'primary' : 'secondary'" @click="activeTab = 'paste'">
                    Pegar lista
                </button>
            </div>

            <label class="wa-imp-code">
                Código de país por defecto (para números locales)
                <input v-model="defaultCountryCode" maxlength="5" placeholder="+1" />
            </label>

            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <p v-if="notice" class="notice success" role="status">{{ notice }}</p>

            <!-- Tab 1: Phone Contact Picker -->
            <section v-if="activeTab === 'phone'" class="wa-imp-box">
                <p class="muted">
                    Abre la libreta de tu celular (donde están sincronizados tus contactos de WhatsApp) y elige todos los clientes que quieras traer de una sola vez.
                </p>
                <button v-if="supportsContactPicker" type="button" class="button primary full" :disabled="busy" @click="pickFromPhone">
                    Elegir contactos del teléfono
                </button>
                <div v-else class="notice">
                    En iPhone o computadora, exporta tus contactos como <strong>.vcf</strong> (desde Contactos o Google Contacts) y súbelo en la pestaña <strong>Archivo .vcf / .csv</strong>.
                </div>
            </section>

            <!-- Tab 2: .vcf / .csv file -->
            <section v-else-if="activeTab === 'file'" class="wa-imp-box">
                <p class="muted">
                    Sube un archivo <strong>.vcf</strong> (vCard de iPhone, Android o Google Contacts) o un <strong>.csv</strong> exportado de WhatsApp / Excel. Los duplicados se omiten automáticamente.
                </p>
                <label class="button secondary full wa-imp-file-btn">
                    Seleccionar archivo .vcf o .csv
                    <input type="file" accept=".vcf,.vcard,.csv,.txt,text/vcard,text/csv" hidden @change="onFileSelected" />
                </label>
            </section>

            <!-- Tab 3: Paste list -->
            <section v-else class="wa-imp-box">
                <label>
                    Pega tus contactos (uno por línea, con nombre y teléfono)
                    <textarea
                        v-model="pasteText"
                        rows="4"
                        placeholder="Daniel +18092374444&#10;María Gómez 809-555-1234&#10;Carlos Méndez, 8295550199"
                    ></textarea>
                </label>
                <button type="button" class="button secondary" :disabled="busy" @click="parsePastedList">
                    Detectar contactos
                </button>
            </section>

            <!-- Preview list -->
            <section v-if="contacts.length" class="wa-imp-preview">
                <div class="wa-imp-preview-head">
                    <strong>Vista previa ({{ selectedContacts.length }} de {{ contacts.length }} seleccionados)</strong>
                    <div class="actions">
                        <button type="button" class="text-button" @click="toggleAll(true)">Marcar todos</button>
                        <button type="button" class="text-button" @click="toggleAll(false)">Desmarcar</button>
                    </div>
                </div>
                <div class="wa-imp-list">
                    <label v-for="c in contacts" :key="c.id" class="wa-imp-row">
                        <input v-model="c.selected" type="checkbox" />
                        <span class="wa-imp-info">
                            <strong>{{ c.full_name }}</strong>
                            <small>{{ c.phone || 'Sin teléfono' }}{{ c.email ? ` · ${c.email}` : '' }}</small>
                        </span>
                    </label>
                </div>
            </section>
        </div>

        <template #footer>
            <button type="button" class="button secondary" :disabled="busy" @click="emit('close')">Cerrar</button>
            <button
                type="button"
                class="button primary"
                :disabled="busy || !selectedContacts.length"
                @click="submitImport"
            >
                {{ busy ? 'Importando…' : `Importar ${selectedContacts.length} ${selectedContacts.length === 1 ? 'contacto' : 'contactos'}` }}
            </button>
        </template>
    </AppDialog>
</template>

<style scoped>
.wa-imp-shell { display: grid; gap: 12px; }
.wa-imp-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
.wa-imp-tabs .button { flex: 1; min-width: 120px; }
.wa-imp-code { max-width: 260px; }
.wa-imp-box { display: grid; gap: 10px; padding: 12px; border-radius: 12px; background: #faf8ff; border: 1px solid #e3dcfb; }
.wa-imp-box p { margin: 0; font-size: 13px; }
.wa-imp-file-btn { cursor: pointer; text-align: center; }
.wa-imp-preview { display: grid; gap: 8px; }
.wa-imp-preview-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; font-size: 13px; }
.wa-imp-list { display: grid; gap: 6px; max-height: 240px; overflow-y: auto; padding-right: 4px; }
.wa-imp-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e6e3f3; background: #fff; cursor: pointer; }
.wa-imp-row input[type="checkbox"] { width: 18px; height: 18px; accent-color: #6435ed; flex-shrink: 0; }
.wa-imp-info { display: grid; gap: 1px; min-width: 0; }
.wa-imp-info strong { font-size: 13.5px; overflow-wrap: anywhere; }
.wa-imp-info small { font-size: 12px; color: #63607d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
