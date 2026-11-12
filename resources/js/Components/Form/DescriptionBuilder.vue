<template>
    <div class="event-description-editor" :class="{ expanded }" @keydown.esc="expanded = false">
        <div class="editor-heading"><div><strong>{{ label }}</strong><span>Редактируйте текст на холсте. Оформление и отступы — в панели справа.</span></div><button type="button" @click="expanded = !expanded">{{ expanded ? 'Свернуть' : 'На весь экран' }}</button></div>
        <p v-if="!ready" class="editor-loading">{{ loadingError || 'Загрузка редактора MagSender…' }}</p>
        <iframe ref="frame" src="/event-editor/event-editor.html" title="Редактор описания мероприятия MagSender" class="editor-frame" :class="{ waiting: !ready }"></iframe>
        <p v-if="error" class="editor-error">{{ error }}</p>
    </div>
</template>
<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
const props = defineProps({ modelValue: { type: String, default: '' }, label: { type: String, default: 'Подробное описание' }, error: { type: String, default: '' }, uploadEndpoint: { type: String, required: true }, eventId: { type: [Number, String], default: null } });
const emit = defineEmits(['update:modelValue']);
const frame = ref(null), ready = ref(false), expanded = ref(false), loadingError = ref('');
const pending = new Map(); let lastEmitted = props.modelValue; let loadingTimer;
function send(data) { frame.value?.contentWindow?.postMessage({ channel: 'magexpert-description', ...data }, window.location.origin); }
function update(html) { lastEmitted = html; emit('update:modelValue', html); }
async function onMessage(event) {
    if (event.origin !== window.location.origin || event.source !== frame.value?.contentWindow || event.data?.channel !== 'magexpert-description') return;
    const data = event.data;
    if (data.type === 'ready') { ready.value = true; clearTimeout(loadingTimer); send({ type: 'init', html: props.modelValue }); }
    if (data.type === 'change') update(data.html);
    if (data.type === 'flushed') {
        const request = pending.get(data.id); if (!request) return;
        clearTimeout(request.timer); pending.delete(data.id);
        if (data.error) request.reject(new Error(data.error)); else { update(data.html); request.resolve(data.html); }
    }
    if (data.type === 'upload') {
        try {
            if (!(data.file instanceof File) || data.file.size > 20 * 1024 * 1024) throw new Error('Допустимы изображения до 20 МБ');
            const body = new FormData(); body.append('image', data.file); if (props.eventId) body.append('event_id', String(props.eventId));
            const response = await window.axios.post(props.uploadEndpoint, body);
            if (!response.data.url) throw new Error('Сервер не вернул адрес изображения');
            send({ type: 'upload-result', id: data.id, result: { url: response.data.url, name: data.file.name, meta: `${Math.round(data.file.size / 1024)} КБ` } });
        } catch (error) { send({ type: 'upload-result', id: data.id, error: error.response?.data?.message || error.message || 'Не удалось загрузить изображение' }); }
    }
}
watch(() => props.modelValue, value => { if (value !== lastEmitted) { lastEmitted = value; if (ready.value) send({ type: 'init', html: value }); } });
onMounted(() => { window.addEventListener('message', onMessage); loadingTimer = setTimeout(() => { if (!ready.value) loadingError.value = 'Редактор не загрузился. Обновите страницу и попробуйте ещё раз.'; }, 20000); });
onBeforeUnmount(() => { clearTimeout(loadingTimer); window.removeEventListener('message', onMessage); for (const request of pending.values()) { clearTimeout(request.timer); request.reject(new Error('Редактор закрыт')); } });
function flush() {
    if (!ready.value) return Promise.resolve(props.modelValue);
    return new Promise((resolve, reject) => {
        const id = crypto.randomUUID(); const timer = setTimeout(() => { pending.delete(id); reject(new Error('Не удалось подготовить описание. Повторите сохранение.')); }, 120000);
        pending.set(id, { resolve, reject, timer }); send({ type: 'flush', id });
    });
}
defineExpose({ flush });
</script>
<style scoped>
.event-description-editor{overflow:hidden;border:1px solid #dee1e8;border-radius:14px;background:#fff}.editor-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:16px 20px;border-bottom:1px solid #dee1e8;color:#1c1f27}.editor-heading strong{display:block;font-size:14px}.editor-heading span{display:block;font-size:12px;color:#7a808e;margin-top:4px}.editor-heading button{flex:none;border:1px solid #dee1e8;border-radius:7px;background:white;padding:8px 12px;font-size:12px;cursor:pointer}.editor-frame{display:block;width:100%;height:820px;border:0;background:white}.waiting{height:0;visibility:hidden}.editor-loading{padding:32px;color:#7a808e;font-size:13px}.editor-error{padding:12px 20px;font-size:13px;color:#b6322d}.expanded{position:fixed;inset:12px;z-index:60;display:flex;flex-direction:column;box-shadow:0 0 0 100vmax #171d2b80}.expanded .editor-frame{flex:1;min-height:0;height:100%}.editor-heading button:focus-visible{outline:2px solid #4c68eb;outline-offset:2px}@media(max-width:640px){.expanded{inset:4px}.editor-heading{padding:12px}.editor-heading span{font-size:11px}.editor-frame{height:1050px}}
</style>
