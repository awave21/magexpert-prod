<template>
    <div class="p-6">
        <h2 class="text-2xl mb-4">WebSocket Test</h2>
        
        <div class="mb-4">
            <button 
                @click="sendTestMessage" 
                class="px-4 py-2 bg-blue-500 text-white rounded"
            >
                Отправить тестовое сообщение
            </button>
        </div>

        <div class="mt-4">
            <h3 class="text-xl mb-2">Полученные сообщения:</h3>
            <div 
                v-for="(message, index) in messages" 
                :key="index"
                class="p-2 mb-2 bg-gray-100 rounded"
            >
                {{ message }}
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const messages = ref([]);

const sendTestMessage = async () => {
    try {
        const response = await axios.post('/api/test-websocket');
        console.log('Сообщение отправлено:', response.data);
        messages.value.push('Сообщение отправлено (локально)');
    } catch (error) {
        console.error('Ошибка при отправке:', error.response?.data || error);
    }
};

onMounted(() => {
    try {
        console.log('Настройки Echo:', window.Echo.connector.options);
        
        const channel = window.Echo.channel('game');
        
        channel.listen('.GameEvent', (e) => {
            console.log('Получено сообщение через WebSocket:', e);
            messages.value.push(e.message);
        });

        console.log('Подписка на канал game активирована');
    } catch (error) {
        console.error('Ошибка при подписке на канал:', error);
    }
});

onUnmounted(() => {
    window.Echo.leaveAllChannels();
});
</script> 