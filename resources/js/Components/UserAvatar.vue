<!-- UserAvatar.vue - Компонент аватара пользователя с инициалами как заглушкой -->
<template>
  <div 
    :class="[
      sizeClass, 
      'inline-grid shrink-0 place-items-center overflow-hidden',
      shape === 'square' ? 'rounded-lg' : 'rounded-full',
      !name && !src ? 'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300' : getBgColorClass()
    ]"
  >
    <img 
      v-if="src && !imageError" 
      :src="avatarUrl" 
      :alt="name || 'Аватар пользователя'" 
      class="h-full w-full object-cover"
      @error="handleImageError"
    />
    <span v-else-if="!name" class="font-medium">?</span>
    <span v-else class="font-medium">{{ getInitials() }}</span>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  name: {
    type: String,
    default: ''
  },
  src: {
    type: String,
    default: ''
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['xs', 'sm', 'md', 'lg', 'xl'].includes(value)
  },
  shape: {
    type: String,
    default: 'circle',
    validator: (value) => ['circle', 'square'].includes(value)
  }
});

const imageError = ref(false);

// Полный URL аватарки
const avatarUrl = computed(() => {
  if (!props.src) return null;
  
  // Если это полный URL, data URL или абсолютный путь, возвращаем как есть
  if (props.src.startsWith('http') || props.src.startsWith('data:') || props.src.startsWith('/')) {
    return props.src;
  }
  
  // Иначе (если это относительный путь в storage) добавляем префикс
  return `/storage/${props.src}`;
});

// Обработчик ошибки загрузки изображения
const handleImageError = () => {
  imageError.value = true;
};

// Получаем первую букву имени пользователя
const getInitials = () => {
  if (!props.name) return '?';
  
  // Разделяем имя на части и берем первые буквы
  const nameParts = props.name.split(' ');
  if (nameParts.length > 1) {
    // Если есть фамилия, берем первые буквы имени и фамилии
    return (nameParts[0].charAt(0) + nameParts[1].charAt(0)).toUpperCase();
  }
  
  // Если только одно слово, берем первую букву
  return props.name.charAt(0).toUpperCase();
};

// Генерируем цвет на основе имени
const getBgColorClass = () => {
  if (!props.name) return 'bg-zinc-200 dark:bg-zinc-700';
  
  // Простой хеш для имени, чтобы получить консистентный цвет
  let hash = 0;
  for (let i = 0; i < props.name.length; i++) {
    hash = props.name.charCodeAt(i) + ((hash << 5) - hash);
  }
  
  // Определяем индекс цвета (у нас 8 цветов)
  const index = Math.abs(hash) % 8;
  
  // Возвращаем соответствующий класс цвета
  const colorClasses = [
    'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
    'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
    'bg-pink-100 text-pink-800 dark:bg-pink-900/30 dark:text-pink-300',
    'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300',
    'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300'
  ];
  
  return colorClasses[index];
};

// Определяем классы размера
const sizeClass = computed(() => {
  const sizes = {
    'xs': 'size-6 text-xs',
    'sm': 'size-8 text-sm',
    'md': 'size-10 text-base',
    'lg': 'size-12 text-lg',
    'xl': 'size-16 text-xl'
  };
  
  return sizes[props.size] || sizes.md;
});
</script> 