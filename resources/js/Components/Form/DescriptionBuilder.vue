<template>
    <div class="description-builder" :class="{ expanded }" @keydown.esc="expanded = false">
        <div class="builder-head">
            <div><strong>{{ label }}</strong><p>Добавляйте текст, изображения и кнопки без HTML.</p></div>
            <div class="builder-actions">
                <button type="button" :aria-pressed="device === 'mobile'" @click="device = device === 'mobile' ? 'desktop' : 'mobile'"><component :is="device === 'mobile' ? ComputerDesktopIcon : DevicePhoneMobileIcon" class="size-4" />{{ device === 'mobile' ? 'Компьютер' : 'Телефон' }}</button>
                <button type="button" :disabled="!past.length" @click="undo">Отменить</button>
                <button type="button" :aria-pressed="preview" @click="preview = !preview">{{ preview ? 'Редактировать' : 'Предпросмотр' }}</button>
                <button type="button" :aria-expanded="expanded" @click="expanded = !expanded">{{ expanded ? 'Свернуть' : 'На весь экран' }}</button>
            </div>
        </div>
        <div class="builder-workspace">
            <aside v-if="!preview" class="builder-palette">
                <strong>Блоки</strong>
                <div class="builder-tiles">
                    <button v-for="type in types" :key="type" type="button" draggable="true" @dragstart="dragType = type" @dragend="dragType = null" @click="add(type)"><component :is="icons[type]" aria-hidden="true" />{{ blockLabels[type] }}</button>
                </div>
                <p>Нажмите на блок или перетащите его в описание. Новый блок появится после выбранного.</p>
                <p>Изменения сохраняются кнопкой «Сохранить мероприятие».</p>
            </aside>
            <div class="builder-canvas" @dragover.prevent @drop.prevent="dropAt(blocks.length)">
                <iframe v-if="preview" :srcdoc="previewDocument" sandbox="" title="Предпросмотр описания мероприятия" class="builder-preview"></iframe>
                <div v-else class="builder-page" :class="{ mobile: device === 'mobile' }">
                    <div v-if="!blocks.length" class="builder-empty"><strong>Начните с заголовка или текста</strong><p>Добавьте блок слева. Кнопку регистрации можно настроить без HTML.</p><button type="button" @click="add('text')">Добавить текст</button></div>
                    <div v-for="(block, index) in blocks" :key="block.id" class="builder-block" :class="{ selected: selected === block.id }" role="button" tabindex="0" :aria-label="`${blockLabels[block.type]}, блок ${index + 1}`" :aria-pressed="selected === block.id" @click.prevent="selected = block.id" @keydown.enter.prevent="selected = block.id" @keydown.space.prevent="selected = block.id" @dragover.prevent.stop @drop.prevent.stop="dropAt(index)">
                        <span class="builder-block-label">{{ blockLabels[block.type] }}</span>
                        <div v-if="block.type === 'image' && !block.src" class="builder-image-empty">Выберите изображение в настройках</div>
                        <div v-else v-html="clean(renderBlock(block))"></div>
                    </div>
                </div>
                <p class="canvas-caption">{{ blocks.length }} блоков · {{ device === 'mobile' ? 'Телефон · 375 px' : 'Страница мероприятия' }}</p>
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
                    <template v-if="active.type === 'zero'">
                        <p class="zero-hint">Свободно разместите текст, фотографии и фигуры на холсте.</p>
                        <button type="button" class="zero-open" @click="zeroOpen = true">Открыть Zero Block ↗</button>
                    </template>
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
                    <template v-if="!['spacer', 'html', 'zero'].includes(active.type)">
                        <label>Выравнивание<select :value="active.align" @change="patch('align', $event.target.value)"><option value="left">Слева</option><option value="center">По центру</option><option value="right">Справа</option></select></label>
                        <label>Отступ снизу, px<input type="number" min="0" max="120" :value="active.spacing" @input="patch('spacing', Number($event.target.value))"></label>
                    </template>
                </template>
                <div v-else class="builder-empty"><strong>Настройки блока</strong><p>Выберите блок в описании, чтобы изменить его содержимое, ссылку или отступы.</p></div>
            </aside>
        </div>
        <ZeroBlockEditor v-if="zeroOpen && active?.type === 'zero'" :value="active.zero" @apply="applyZero" @close="zeroOpen = false" />
        <p v-if="error" class="builder-error">{{ error }}</p>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Bars3BottomLeftIcon, PhotoIcon, CursorArrowRaysIcon, MinusIcon, ArrowsUpDownIcon, Squares2X2Icon, H2Icon, DevicePhoneMobileIcon, ComputerDesktopIcon } from '@heroicons/vue/24/outline';
import ZeroBlockEditor from './ZeroBlockEditor.vue';
import DOMPurify from 'dompurify';
import { QuillEditor } from '@vueup/vue-quill';
import '@vueup/vue-quill/dist/vue-quill.snow.css';
import { useToast } from 'vue-toastification';
import { blockLabels, createBlock, parseBlocks, renderBlock, safeUrl } from '@/Support/descriptionBlocks';

const props = defineProps({ modelValue: { type: String, default: '' }, label: { type: String, default: 'Подробное описание' }, error: { type: String, default: '' }, uploadEndpoint: { type: String, required: true }, eventId: { type: [Number, String], default: null } });
const emit = defineEmits(['update:modelValue']);
const toast = useToast();
const types = ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'zero'];
const icons = { heading: H2Icon, text: Bars3BottomLeftIcon, image: PhotoIcon, button: CursorArrowRaysIcon, divider: MinusIcon, spacer: ArrowsUpDownIcon, zero: Squares2X2Icon };
const device = ref('desktop');
const zeroOpen = ref(false);
function applyZero(value) { patch('zero', JSON.parse(JSON.stringify(value))); zeroOpen.value = false; }
const blocks = ref([]), selected = ref(null), expanded = ref(false), preview = ref(false), past = ref([]), dragType = ref(null), uploading = ref(false);
const active = computed(() => blocks.value.find(b => b.id === selected.value));
const position = computed(() => blocks.value.findIndex(b => b.id === selected.value));
let mounted = false;
let lastEmitted = props.modelValue;
const clean = value => DOMPurify.sanitize(value);
const previewDocument = computed(() => `<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{margin:24px;font:16px/1.75 system-ui;color:#18181b;overflow-wrap:anywhere}img{max-width:100%;height:auto}p{margin-top:0}h2{font-size:22px}
/* Workspace proportions follow the MagSender editor. */
.description-builder{border-color:#e4e6ec;border-radius:16px;box-shadow:0 4px 24px -20px #1c1f2740;font-family:ui-sans-serif,system-ui,sans-serif}
.builder-head{min-height:76px;padding:18px 22px;background:#fff}.builder-head strong{font-size:16px;letter-spacing:-.025em}.builder-head p{font-size:12px;margin-top:4px}
.builder-actions{gap:8px}.builder-actions button{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-color:#e4e6ec;background:#fff;height:34px;padding:0 11px;font-weight:500}.builder-actions button svg{width:16px;height:16px}
.builder-workspace{grid-template-columns:208px minmax(0,1fr) 272px;min-height:650px}.builder-palette{padding:24px 18px;background:#fff}.builder-palette>strong{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#9298a5}.builder-tiles{grid-template-columns:1fr 1fr;gap:10px;margin:18px 0 22px}.builder-tiles button{height:82px;padding:12px 6px;display:flex;flex-direction:column;justify-content:center;gap:9px;border:1px solid #e7e9ef;border-radius:12px;background:#f8f9fc;font-size:11px;font-weight:500;color:#535c70;transition:transform .15s,border-color .15s,background .15s}.builder-tiles button svg{height:23px;width:23px;color:#4c68eb;stroke-width:1.5}.builder-tiles button:hover{transform:translateY(-2px);border-color:#8c9ef3;background:#eef1ff}.builder-palette p{font-size:11px;line-height:1.8;color:#8a909e}
.builder-canvas{padding:48px 34px 32px;background:#eef0f5;display:flex;flex-direction:column;align-items:center;gap:18px}.builder-page{width:100%;max-width:760px;background:#fff;box-shadow:0 1px 3px #1c1f2710,0 14px 40px -20px #1c1f2740;min-height:480px;padding:28px 0;border-radius:3px;transition:max-width .25s}.builder-page.mobile{max-width:375px}.builder-block{padding:12px 28px 0;border:0;outline:2px solid transparent;outline-offset:-2px;transition:outline-color .15s}.builder-block:hover{outline-color:#b9c5fa}.builder-block.selected{outline-color:#4c68eb}.builder-block-label{display:none}.builder-block.selected .builder-block-label{display:block;position:absolute;top:-21px;left:0;border-radius:5px 5px 0 0;background:#4c68eb;color:white;font-size:10px;padding:3px 8px;z-index:2}.builder-block.selected{z-index:1}.canvas-caption{font-size:11px;color:#8b91a0;text-align:center}.builder-inspector{padding:24px 18px;background:#fafbfe;border-color:#e4e6ec}.builder-inspector-head{font-size:14px;margin-bottom:20px}.builder-inspector-head span{font-size:11px;color:#9298a5}.builder-inspector label{font-size:11px;color:#737b8e;gap:8px;margin-top:22px}.builder-inspector input,.builder-inspector textarea,.builder-inspector select{background:white;border-color:#e0e4ed;border-radius:8px;color:#252b39;padding:10px;font-size:13px;box-shadow:0 1px 2px #1c1f2703}.builder-rich :deep(.ql-toolbar){border-radius:8px 8px 0 0;border-color:#e0e4ed}.builder-rich :deep(.ql-container){border-color:#e0e4ed;border-radius:0 0 8px 8px}.expanded{inset:12px;z-index:60;display:flex;flex-direction:column}.expanded .builder-workspace{flex:1}.expanded .builder-page{max-width:760px}.expanded .builder-page.mobile{max-width:375px}
:global(.dark) .builder-head,:global(.dark) .builder-palette{background:#18181b}:global(.dark) .builder-inspector{background:#202024}:global(.dark) .builder-tiles button,:global(.dark) .builder-actions button{background:#27272a;border-color:#3f3f46;color:#d4d4d8}
@media(max-width:1200px){.builder-workspace{grid-template-columns:168px minmax(0,1fr) 240px}.builder-canvas{padding:36px 20px}.builder-palette{padding:20px 12px}.builder-inspector{grid-column:auto;border-left:1px solid #e4e6ec;border-top:0}}
@media(max-width:950px){.builder-workspace{grid-template-columns:148px minmax(0,1fr)}.builder-inspector{grid-column:1/-1;border-left:0;border-top:1px solid #e4e6ec}.builder-page{min-height:320px}}
@media(max-width:640px){.builder-workspace{display:flex;min-height:0}.builder-palette{padding:14px}.builder-palette>strong{display:none}.builder-tiles{grid-template-columns:repeat(4,minmax(0,1fr));margin:0;gap:7px}.builder-tiles button{height:66px;font-size:10px;gap:6px}.builder-tiles button svg{height:20px;width:20px}.builder-canvas{padding:28px 12px 18px}.builder-head{padding:16px}.builder-block{padding-left:18px;padding-right:18px}.builder-inspector{padding:20px}.builder-head p{max-width:240px}.builder-actions{gap:5px}.builder-actions button{font-size:11px;padding:0 8px}}
.zero-hint{font-size:12px;line-height:1.8;color:#8a909e;margin:20px 0}.description-builder .zero-open{width:100%;padding:12px;background:#4c68eb;color:white;border:0;border-radius:8px}.builder-block :deep(svg){max-width:100%}</style><body>${clean(blocks.value.map(renderBlock).join(''))}</body></html>`);
function load(value) { blocks.value = parseBlocks(value, new DOMParser()); selected.value = blocks.value[0]?.id || null; past.value = []; }
onMounted(() => { mounted = true; load(props.modelValue); });
watch(() => props.modelValue, value => { if (mounted && value !== lastEmitted) { lastEmitted = value; load(value); } });
function remember() { past.value.push(JSON.stringify(blocks.value)); if (past.value.length > 50) past.value.shift(); }
function publish() { lastEmitted = blocks.value.map(renderBlock).join(''); emit('update:modelValue', lastEmitted); }
function patch(key, value) { if (!active.value || active.value[key] === value) return; remember(); active.value[key] = value; active.value.original = null; publish(); }
function add(type, index = position.value < 0 ? blocks.value.length : position.value + 1) { remember(); const b = createBlock(type); blocks.value.splice(index, 0, b); selected.value = b.id; if (type === 'zero') zeroOpen.value = true; publish(); }
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

/* Workspace proportions follow the MagSender editor. */
.description-builder{border-color:#e4e6ec;border-radius:16px;box-shadow:0 4px 24px -20px #1c1f2740;font-family:ui-sans-serif,system-ui,sans-serif}
.builder-head{min-height:76px;padding:18px 22px;background:#fff}.builder-head strong{font-size:16px;letter-spacing:-.025em}.builder-head p{font-size:12px;margin-top:4px}
.builder-actions{gap:8px}.builder-actions button{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-color:#e4e6ec;background:#fff;height:34px;padding:0 11px;font-weight:500}.builder-actions button svg{width:16px;height:16px}
.builder-workspace{grid-template-columns:208px minmax(0,1fr) 272px;min-height:650px}.builder-palette{padding:24px 18px;background:#fff}.builder-palette>strong{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#9298a5}.builder-tiles{grid-template-columns:1fr 1fr;gap:10px;margin:18px 0 22px}.builder-tiles button{height:82px;padding:12px 6px;display:flex;flex-direction:column;justify-content:center;gap:9px;border:1px solid #e7e9ef;border-radius:12px;background:#f8f9fc;font-size:11px;font-weight:500;color:#535c70;transition:transform .15s,border-color .15s,background .15s}.builder-tiles button svg{height:23px;width:23px;color:#4c68eb;stroke-width:1.5}.builder-tiles button:hover{transform:translateY(-2px);border-color:#8c9ef3;background:#eef1ff}.builder-palette p{font-size:11px;line-height:1.8;color:#8a909e}
.builder-canvas{padding:48px 34px 32px;background:#eef0f5;display:flex;flex-direction:column;align-items:center;gap:18px}.builder-page{width:100%;max-width:760px;background:#fff;box-shadow:0 1px 3px #1c1f2710,0 14px 40px -20px #1c1f2740;min-height:480px;padding:28px 0;border-radius:3px;transition:max-width .25s}.builder-page.mobile{max-width:375px}.builder-block{padding:12px 28px 0;border:0;outline:2px solid transparent;outline-offset:-2px;transition:outline-color .15s}.builder-block:hover{outline-color:#b9c5fa}.builder-block.selected{outline-color:#4c68eb}.builder-block-label{display:none}.builder-block.selected .builder-block-label{display:block;position:absolute;top:-21px;left:0;border-radius:5px 5px 0 0;background:#4c68eb;color:white;font-size:10px;padding:3px 8px;z-index:2}.builder-block.selected{z-index:1}.canvas-caption{font-size:11px;color:#8b91a0;text-align:center}.builder-inspector{padding:24px 18px;background:#fafbfe;border-color:#e4e6ec}.builder-inspector-head{font-size:14px;margin-bottom:20px}.builder-inspector-head span{font-size:11px;color:#9298a5}.builder-inspector label{font-size:11px;color:#737b8e;gap:8px;margin-top:22px}.builder-inspector input,.builder-inspector textarea,.builder-inspector select{background:white;border-color:#e0e4ed;border-radius:8px;color:#252b39;padding:10px;font-size:13px;box-shadow:0 1px 2px #1c1f2703}.builder-rich :deep(.ql-toolbar){border-radius:8px 8px 0 0;border-color:#e0e4ed}.builder-rich :deep(.ql-container){border-color:#e0e4ed;border-radius:0 0 8px 8px}.expanded{inset:12px;z-index:60;display:flex;flex-direction:column}.expanded .builder-workspace{flex:1}.expanded .builder-page{max-width:760px}.expanded .builder-page.mobile{max-width:375px}
:global(.dark) .builder-head,:global(.dark) .builder-palette{background:#18181b}:global(.dark) .builder-inspector{background:#202024}:global(.dark) .builder-tiles button,:global(.dark) .builder-actions button{background:#27272a;border-color:#3f3f46;color:#d4d4d8}
@media(max-width:1200px){.builder-workspace{grid-template-columns:168px minmax(0,1fr) 240px}.builder-canvas{padding:36px 20px}.builder-palette{padding:20px 12px}.builder-inspector{grid-column:auto;border-left:1px solid #e4e6ec;border-top:0}}
@media(max-width:950px){.builder-workspace{grid-template-columns:148px minmax(0,1fr)}.builder-inspector{grid-column:1/-1;border-left:0;border-top:1px solid #e4e6ec}.builder-page{min-height:320px}}
@media(max-width:640px){.builder-workspace{display:flex;min-height:0}.builder-palette{padding:14px}.builder-palette>strong{display:none}.builder-tiles{grid-template-columns:repeat(4,minmax(0,1fr));margin:0;gap:7px}.builder-tiles button{height:66px;font-size:10px;gap:6px}.builder-tiles button svg{height:20px;width:20px}.builder-canvas{padding:28px 12px 18px}.builder-head{padding:16px}.builder-block{padding-left:18px;padding-right:18px}.builder-inspector{padding:20px}.builder-head p{max-width:240px}.builder-actions{gap:5px}.builder-actions button{font-size:11px;padding:0 8px}}
.zero-hint{font-size:12px;line-height:1.8;color:#8a909e;margin:20px 0}.description-builder .zero-open{width:100%;padding:12px;background:#4c68eb;color:white;border:0;border-radius:8px}.builder-block :deep(svg){max-width:100%}</style>
