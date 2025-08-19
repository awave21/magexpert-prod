<template>
  <div class="w-full">
    <label v-if="label" class="block text-sm font-medium text-zinc-900 dark:text-white mb-1">{{ label }}</label>
    <QuillEditor
      ref="editorRef"
      v-model:content="localContent"
      contentType="html"
      theme="snow"
      :placeholder="placeholder"
      :options="editorOptions"
      class="bg-white dark:bg-zinc-900 dark:text-white rounded-lg border border-zinc-200 dark:border-zinc-700"
    />
    <p v-if="hint" class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ hint }}</p>
    <p v-if="error" class="mt-1 text-sm text-brandcoral dark:text-brandcoral">{{ error }}</p>
  </div>
  
</template>

<script setup>
import { ref, watch, computed, onMounted } from 'vue';
import { QuillEditor } from '@vueup/vue-quill';
import '@vueup/vue-quill/dist/vue-quill.snow.css';
import { useToast } from 'vue-toastification';
import { Quill } from '@vueup/vue-quill';

const props = defineProps({
  modelValue: {
    type: String,
    default: ''
  },
  label: {
    type: String,
    default: ''
  },
  error: {
    type: String,
    default: ''
  },
  hint: {
    type: String,
    default: ''
  },
  placeholder: {
    type: String,
    default: 'Введите текст...'
  },
  uploadEndpoint: {
    type: String,
    required: true
  },
  eventId: {
    type: [Number, String, null],
    default: null
  },
  maxSizeMb: {
    type: Number,
    default: 20
  }
});

const emit = defineEmits(['update:modelValue']);
const toast = useToast();
const editorRef = ref(null);
const localContent = ref(props.modelValue || '');

watch(() => props.modelValue, (val) => {
  if (val !== localContent.value) {
    localContent.value = val || '';
  }
});

watch(localContent, (val) => {
  if (val !== props.modelValue) {
    emit('update:modelValue', val);
  }
});

// Кастомная кнопка удаления выбранного изображения
let removeImageHandler;

// Настройка панели инструментов: H2–H6, обычный абзац, жирный, курсив, подчеркивание, списки, ссылка, изображение, удалить изображение, очистка
const toolbarOptions = [
  [{ header: [2, 3, 4, 5, 6, false] }],
  ['bold', 'italic', 'underline'],
  [{ list: 'ordered' }, { list: 'bullet' }],
  ['link', 'image', 'removeImage'],
  ['clean']
];

function openFileDialog(accept = 'image/*') {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = accept;
    input.onchange = () => resolve(input.files && input.files[0] ? input.files[0] : null);
    input.click();
  });
}

async function uploadImage(file) {
  try {
    if (!file) return null;
    if (file.size > props.maxSizeMb * 1024 * 1024) {
      toast.error(`Размер файла превышает ${props.maxSizeMb}MB`);
      return null;
    }
    const formData = new FormData();
    formData.append('image', file);
    if (props.eventId) {
      formData.append('event_id', String(props.eventId));
    }
    const response = await window.axios.post(props.uploadEndpoint, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    return response?.data?.url || null;
  } catch (e) {
    toast.error('Ошибка загрузки изображения');
    return null;
  }
}

// Кастомный обработчик вставки изображения: загружаем на сервер и вставляем по URL
function imageHandler() {
  // this.quill доступен внутри обработчика Quill
  const quill = this.quill;
  openFileDialog('image/*').then(async (file) => {
    if (!file) return;
    const url = await uploadImage(file);
    if (!url) return;
    const range = quill.getSelection(true);
    quill.insertEmbed(range ? range.index : 0, 'image', url, 'user');
    if (range) quill.setSelection(range.index + 1, 0);
  });
}

// Обработчик удаления выбранного изображения: удаляет <img> под кареткой и делает вызов API
async function handleRemoveImage(quill) {
  const range = quill.getSelection(true);
  if (!range) return;
  const [blot, offset] = quill.getLeaf(range.index);
  if (!blot) return;
  const node = blot && blot.domNode ? blot.domNode : null;
  if (!node) return;
  // Ищем ближайший IMG узел
  let imgEl = node.tagName === 'IMG' ? node : node.querySelector ? node.querySelector('img') : null;
  if (!imgEl) {
    // иногда каретка рядом; попробуем посмотреть вокруг
    const line = quill.getLine(range.index);
    if (line && line[0]) {
      const dom = line[0].domNode;
      imgEl = dom && dom.querySelector ? dom.querySelector('img') : null;
    }
  }
  if (!imgEl || !imgEl.getAttribute) return;
  const url = imgEl.getAttribute('src');
  try {
    await window.axios.post(route('admin.events.delete-image'), { url });
  } catch (e) {
    // Мягко игнорируем ошибку удаления на сервере
  }
  // Удаляем изображение из редактора
  const blotIdx = range.index;
  quill.deleteText(blotIdx, 1, 'user');
}

removeImageHandler = function () {
  const quill = this.quill;
  handleRemoveImage(quill);
};

const editorOptions = computed(() => ({
  modules: {
    toolbar: {
      container: toolbarOptions,
      handlers: {
        image: imageHandler,
        removeImage: removeImageHandler
      }
    }
  }
}));

</script>

<style scoped>
.ql-container {
  min-height: 220px;
}
.ql-editor {
  min-height: 180px;
}
/* Кнопка удаления изображения в тулбаре */
.ql-toolbar .ql-formats button.ql-removeImage::after {
  content: "🗑";
  font-size: 16px;
  line-height: 1;
}
</style>