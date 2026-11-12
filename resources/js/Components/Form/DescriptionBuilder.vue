<template>
    <div class="description-builder" :class="{ expanded }" @keydown.esc="expanded = false">
        <div class="builder-head">
            <div><strong>{{ label }}</strong><p>Добавляйте текст, изображения и кнопки без HTML.</p></div>
            <div class="builder-actions">
                <button type="button" :disabled="!past.length" @click="undo">Отменить</button>
                <button type="button" :aria-pressed="preview" @click="preview = !preview">{{ preview ? 'Редактировать' : 'Предпросмотр' }}</button>
                <button type="button" :aria-expanded="expanded" @click="expanded = !expanded">{{ expanded ? 'Свернуть' : 'На весь экран' }}</button>
            </div>
        </div>
        <div class="builder-workspace">
            <aside v-if="!preview" class="builder-palette">
                <strong>Блоки</strong>
                <div class="builder-tiles">
                    <button v-for="type in types" :key="type" type="button" draggable="true" @dragstart="dragType = type" @dragend="dragType = null" @click="add(type)"><span aria-hidden="true">{{ icons[type] }}</span>{{ blockLabels[type] }}</button>
                </div>
                <p>Нажмите на блок или перетащите его в описание. Новый блок появится после выбранного.</p>
                <p>Изменения сохраняются кнопкой «Сохранить мероприятие».</p>
            </aside>
            <div class="builder-canvas" @dragover.prevent @drop.prevent="dropAt(blocks.length)">
                <iframe v-if="preview" :srcdoc="previewDocument" sandbox="" title="Предпросмотр описания мероприятия" class="builder-preview"></iframe>
                <template v-else>
                    <div v-if="!blocks.length" class="builder-empty"><strong>Начните с заголовка или текста</strong><p>Добавьте блок слева. Кнопку регистрации можно настроить без HTML.</p><button type="button" @click="add('text')">Добавить текст</button></div>
                    <div v-for="(block, index) in blocks" :key="block.id" class="builder-block" :class="{ selected: selected === block.id }" role="button" tabindex="0" :aria-label="`${blockLabels[block.type]}, блок ${index + 1}`" :aria-pressed="selected === block.id" @click.prevent="selected = block.id" @keydown.enter.prevent="selected = block.id" @keydown.space.prevent="selected = block.id" @dragover.prevent.stop @drop.prevent.stop="dropAt(index)">
                        <span class="builder-block-label">{{ blockLabels[block.type] }}</span>
                        <div v-if="block.type === 'image' && !block.src" class="builder-image-empty">Выберите изображение в настройках</div>
                        <div v-else v-html="clean(renderBlock(block))"></div>
                    </div>
                </template>
            </div>
            <aside v-if="!preview" class="builder-inspector">
                <template v-if="active">
                    <div class="builder-inspector-head"><strong>{{ blockLabels[active.type] }}</strong><span>{{ position + 1 }} / {{ blocks.length }}</span></div>
                    <div class="builder-actions">
                        <button type="button" :disabled="position === 0" aria-label="Переместить блок выше" @click="move(-1)">↑</button>
                        <button type="button" :disabled="position === blocks.length - 1" aria-label="Переместить блок ниже" @click="move(1)">↓</button>
                        <button type="button" @click="duplicate">Копия</button>
                        <button type="button" class="builder-delete" @click="remove">Удалить</button>
                    </div>
                    <label v-if="['heading', 'button'].includes(active.type)">{{ active.type === 'button' ? 'Текст кнопки' : 'Текст заголовка' }}<textarea :value="active.text" rows="3" @input="patch('text', $event.target.value)"></textarea></label>
                    <template v-if="active.type === 'text'">
                        <div class="builder-rich" :key="active.id"><QuillEditor :content="active.html" content-type="html" theme="snow" :toolbar="[['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['link', 'clean']]" @update:content="patch('html', $event)" /></div>
                    </template>
                    <template v-if="active.type === 'button'">
                        <label>Ссылка<input :value="active.href" type="url" placeholder="https://…" @input="patch('href', $event.target.value)"></label>
                        <p v-if="active.href && !safeUrl(active.href)" class="builder-error">Укажите ссылку, начинающуюся с https:// или http://.</p>
                        <label>Цвет кнопки<input type="color" :value="active.color" @input="patch('color', $event.target.value)"></label>
                    </template>
                    <template v-if="active.type === 'image'">
                        <label class="builder-upload">{{ uploading ? 'Загрузка…' : 'Загрузить изображение' }}<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" :disabled="uploading" @change="upload"></label>
                        <label>Ссылка на изображение<input :value="active.src" type="url" placeholder="https://…" @input="patch('src', $event.target.value)"></label>
                        <label>Описание изображения<input :value="active.alt" @input="patch('alt', $event.target.value)"></label>
                    </template>
                    <label v-if="active.type === 'html'">Существующий HTML<textarea :value="active.html" rows="12" class="builder-code" @input="patch('html', $event.target.value)"></textarea><span>Сложный фрагмент сохранён без изменения. Вокруг него можно добавлять визуальные блоки.</span></label>
                    <label v-if="active.type === 'spacer'">Высота, px<input type="number" min="0" max="240" :value="active.height" @input="patch('height', Number($event.target.value))"></label>
                    <template v-if="!['spacer', 'html'].includes(active.type)">
                        <label>Выравнивание<select :value="active.align" @change="patch('align', $event.target.value)"><option value="left">Слева</option><option value="center">По центру</option><option value="right">Справа</option></select></label>
                        <label>Отступ снизу, px<input type="number" min="0" max="120" :value="active.spacing" @input="patch('spacing', Number($event.target.value))"></label>
                    </template>
                </template>
                <div v-else class="builder-empty"><strong>Настройки блока</strong><p>Выберите блок в описании, чтобы изменить его содержимое, ссылку или отступы.</p></div>
            </aside>
        </div>
        <p v-if="error" class="builder-error">{{ error }}</p>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import DOMPurify from 'dompurify';
import { QuillEditor } from '@vueup/vue-quill';
import '@vueup/vue-quill/dist/vue-quill.snow.css';
import { useToast } from 'vue-toastification';
import { blockLabels, createBlock, parseBlocks, renderBlock, safeUrl } from '@/Support/descriptionBlocks';

const props = defineProps({ modelValue: { type: String, default: '' }, label: { type: String, default: 'Подробное описание' }, error: { type: String, default: '' }, uploadEndpoint: { type: String, required: true }, eventId: { type: [Number, String], default: null } });
const emit = defineEmits(['update:modelValue']);
const toast = useToast();
const types = ['heading', 'text', 'image', 'button', 'divider', 'spacer'];
const icons = { heading: 'H₂', text: 'Тт', image: '▧', button: '▰', divider: '―', spacer: '↕' };
const blocks = ref([]), selected = ref(null), expanded = ref(false), preview = ref(false), past = ref([]), dragType = ref(null), uploading = ref(false);
const active = computed(() => blocks.value.find(b => b.id === selected.value));
const position = computed(() => blocks.value.findIndex(b => b.id === selected.value));
let mounted = false;
let lastEmitted = props.modelValue;
const clean = value => DOMPurify.sanitize(value);
const previewDocument = computed(() => `<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{margin:24px;font:16px/1.75 system-ui;color:#18181b;overflow-wrap:anywhere}img{max-width:100%;height:auto}p{margin-top:0}h2{font-size:22px}</style><body>${clean(blocks.value.map(renderBlock).join(''))}</body></html>`);
function load(value) { blocks.value = parseBlocks(value, new DOMParser()); selected.value = blocks.value[0]?.id || null; past.value = []; }
onMounted(() => { mounted = true; load(props.modelValue); });
watch(() => props.modelValue, value => { if (mounted && value !== lastEmitted) { lastEmitted = value; load(value); } });
function remember() { past.value.push(JSON.stringify(blocks.value)); if (past.value.length > 50) past.value.shift(); }
function publish() { lastEmitted = blocks.value.map(renderBlock).join(''); emit('update:modelValue', lastEmitted); }
function patch(key, value) { if (!active.value || active.value[key] === value) return; remember(); active.value[key] = value; active.value.original = null; publish(); }
function add(type, index = position.value < 0 ? blocks.value.length : position.value + 1) { remember(); const b = createBlock(type); blocks.value.splice(index, 0, b); selected.value = b.id; publish(); }
function dropAt(index) { if (dragType.value) add(dragType.value, index); dragType.value = null; }
function remove() { remember(); const index = position.value; blocks.value.splice(index, 1); selected.value = blocks.value[Math.min(index, blocks.value.length - 1)]?.id || null; publish(); }
function move(direction) { const index = position.value; const target = index + direction; if (target < 0 || target >= blocks.value.length) return; remember(); const [block] = blocks.value.splice(index, 1); blocks.value.splice(target, 0, block); publish(); }
function duplicate() { remember(); const b = { ...active.value, id: crypto.randomUUID() }; blocks.value.splice(position.value + 1, 0, b); selected.value = b.id; publish(); }
function undo() { if (!past.value.length) return; blocks.value = JSON.parse(past.value.pop()); if (!active.value) selected.value = blocks.value[0]?.id || null; publish(); }
async function upload(event) {
    const file = event.target.files?.[0]; const blockId = selected.value;
    if (!file) return;
    if (file.size > 20 * 1024 * 1024) { toast.error('Размер изображения не должен превышать 20 МБ'); return; }
    uploading.value = true;
    try {
        const data = new FormData(); data.append('image', file); if (props.eventId) data.append('event_id', String(props.eventId));
        const response = await window.axios.post(props.uploadEndpoint, data);
        const block = blocks.value.find(b => b.id === blockId);
        if (block && response.data.url) { remember(); block.src = response.data.url; block.original = null; publish(); }
    } catch { toast.error('Не удалось загрузить изображение. Попробуйте ещё раз.'); }
    finally { uploading.value = false; event.target.value = ''; }
}
</script>

<style scoped>
.description-builder{border:1px solid #d4d4d8;border-radius:12px;overflow:hidden;color:#18181b;background:#fff}.expanded{position:fixed;inset:16px;z-index:50;overflow:auto;box-shadow:0 0 0 100vmax #0008}.builder-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;border-bottom:1px solid #e4e4e7;flex-wrap:wrap}.builder-head p,.builder-palette p,.builder-empty p{font-size:12px;color:#71717a;line-height:1.6;margin:6px 0}.builder-actions{display:flex;gap:6px;flex-wrap:wrap}.description-builder button,.builder-upload{border:1px solid #d4d4d8;border-radius:6px;padding:7px 10px;font-size:12px;background:transparent;cursor:pointer;transition:background .15s}.description-builder button:hover{background:#f4f4f5}.description-builder button:focus-visible,.builder-block:focus-visible{outline:2px solid #4c68eb;outline-offset:2px}.description-builder button:disabled{opacity:.35;cursor:default}.builder-workspace{display:grid;grid-template-columns:140px minmax(0,1fr) 260px;min-height:500px}.builder-palette,.builder-inspector{padding:14px;min-width:0}.builder-palette{border-right:1px solid #e4e4e7}.builder-inspector{border-left:1px solid #e4e4e7}.builder-tiles{display:grid;gap:8px;margin:14px 0}.builder-tiles button{display:flex;align-items:center;gap:8px;text-align:left}.builder-tiles span{width:20px;font-weight:600}.builder-canvas{background:#f4f4f5;padding:24px 16px;min-width:0;overflow-wrap:anywhere}.builder-block{position:relative;padding:14px 12px 2px;background:#fff;border:2px solid transparent;cursor:pointer;min-height:42px}.builder-block:hover{border-color:#a1a1aa}.builder-block.selected{border-color:#4c68eb}.builder-block-label{display:block;font:10px/1.5 system-ui;color:#71717a;margin-bottom:6px}.builder-block :deep(img){max-width:100%;height:auto}.builder-block :deep(a){pointer-events:none}.builder-inspector-head{display:flex;justify-content:space-between;margin-bottom:14px;font-size:13px}.builder-inspector label{display:grid;gap:6px;margin-top:18px;font-size:12px;font-weight:500}.builder-inspector input,.builder-inspector textarea,.builder-inspector select{width:100%;min-width:0;border:1px solid #d4d4d8;border-radius:6px;padding:8px;font-size:13px;background:transparent}.builder-inspector input[type=color]{height:38px}.builder-inspector label span{color:#71717a;font-weight:400}.builder-rich{margin-top:18px;background:#fff;color:#18181b}.builder-rich :deep(.ql-container){min-height:180px;font-size:15px}.builder-rich :deep(.ql-editor){min-height:180px}.builder-code{font-family:monospace}.builder-empty{padding:32px 12px;text-align:center;font-size:14px}.builder-empty button{margin-top:12px}.builder-image-empty{padding:40px 10px;background:#f4f4f5;text-align:center;font-size:13px;color:#71717a}.builder-preview{border:0;background:white;width:100%;height:65vh}.builder-error,.builder-delete{color:#dc2626}.builder-error{padding:8px 14px;font-size:13px}.builder-upload input{display:block;margin-top:8px;font-size:11px;max-width:100%}:global(.dark) .description-builder{background:#18181b;color:#fafafa;border-color:#3f3f46}:global(.dark) .builder-head,:global(.dark) .builder-palette,:global(.dark) .builder-inspector{border-color:#3f3f46}:global(.dark) .description-builder button:hover{background:#3f3f46}.builder-block{color:#18181b}.builder-block :deep(h2){font-size:22px;font-weight:700}.builder-block :deep(ul){list-style:disc;padding-left:24px}.builder-block :deep(ol){list-style:decimal;padding-left:24px}.builder-block :deep(p){margin-bottom:12px}@media(max-width:1100px){.builder-workspace{grid-template-columns:120px minmax(0,1fr)}.builder-inspector{grid-column:1/-1;border-left:0;border-top:1px solid #e4e4e7}.builder-tiles{gap:6px}}@media(max-width:640px){.builder-workspace{display:flex;flex-direction:column}.builder-palette{border-right:0;border-bottom:1px solid #e4e4e7}.builder-tiles{grid-template-columns:repeat(3,minmax(0,1fr))}.builder-tiles button{padding:8px 4px;font-size:11px}.builder-palette p{display:none}.expanded{inset:4px}.builder-canvas{padding:12px}.builder-head{padding:12px}}
</style>
