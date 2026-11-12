<template>
    <div class="file-dropzone" :class="{ 'is-dragging': dragging }" :aria-label="label" @dragenter.prevent.stop="enter" @dragover.prevent.stop="over" @dragleave.prevent.stop="leave" @drop.prevent.stop="drop">
        <div class="dropzone-hint"><ArrowUpTrayIcon aria-hidden="true" /><span>{{ dragging ? 'Отпустите файл здесь' : label }}</span></div>
        <slot />
        <p class="dropzone-note">или выберите файл по кнопке</p>
    </div>
</template>
<script setup>
import { ref } from 'vue';
import { ArrowUpTrayIcon } from '@heroicons/vue/24/outline';
import { useToast } from 'vue-toastification';
import { uploadError } from '@/Support/uploadValidation';
const props = defineProps({ label: { type: String, required: true }, accept: { type: String, required: true }, maxSizeMb: { type: Number, required: true } });
const emit = defineEmits(['file']);
const dragging = ref(false); let depth = 0;
const toast = useToast();
function enter(event) { if (event.dataTransfer?.types.includes('Files')) { depth++; dragging.value = true; } }
function over(event) { if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy'; }
function leave() { depth = Math.max(0, depth - 1); if (!depth) dragging.value = false; }
function drop(event) {
    depth = 0; dragging.value = false;
    const files = event.dataTransfer?.files;
    if (!files?.length) return;
    if (files.length !== 1) { toast.error('Перетащите один файл.'); return; }
    const error = uploadError(files[0], props.accept, props.maxSizeMb);
    if (error) { toast.error(error); return; }
    emit('file', files[0]);
}
</script>
<style scoped>
.file-dropzone{min-height:192px;display:flex;flex-direction:column;align-items:flex-start;justify-content:center;gap:14px;margin-top:6px;padding:22px;border:1px dashed #cbd0dc;border-radius:12px;background:#fafbfe;transition:border-color .15s,background .15s,box-shadow .15s}.file-dropzone.is-dragging{border-color:#4c68eb;background:#eef1ff;box-shadow:inset 0 0 0 1px #4c68eb}.dropzone-hint{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:500;color:#535c70}.dropzone-hint svg{width:20px;height:20px;color:#7a86a4;flex:none}.dropzone-note{margin:0;font-size:11px;color:#9298a5}:global(.dark) .file-dropzone{background:#202024;border-color:#52525b}:global(.dark) .file-dropzone.is-dragging{background:#262d52;border-color:#8c9ef3}:global(.dark) .dropzone-hint{color:#d4d4d8}
</style>
