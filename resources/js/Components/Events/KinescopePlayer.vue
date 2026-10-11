<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import loadKinescope from './loadKinescope';

// Плеер Кинескопа через его IFrame API: запоминает, где врач остановился, и продолжает с этого места на любом устройстве.
const props = defineProps({
    videoId: { type: String, default: null },
    playlistId: { type: String, default: null },
    embedUrl: { type: String, required: true },
    // Позиции из базы: { last: 'ID ролика', items: { ID: { position, duration, completed } } }
    progress: { type: Object, default: () => ({ last: null, items: {} }) },
    saveUrl: { type: String, default: null },
    // Во время прямого эфира позицию не запоминаем
    track: { type: Boolean, default: true },
});

const MIN_RESUME = 15;
const SAVE_EVERY = 10;

const host = ref(null);
const failed = ref(false);
const resumeAt = ref(null);

const items = { ...(props.progress?.items ?? {}) };
let player = null;
let current = { key: null, time: 0, duration: null };
let pendingRestore = false;
let playedSinceSave = 0;
let lastTick = null;
let lastSent = '';
let hideTimer = null;

function formatTime(seconds) {
    const total = Math.max(0, Math.floor(seconds));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = String(total % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function save() {
    if (!props.track || !props.saveUrl || !current.key) {
        return;
    }
    const position = Math.floor(current.time);
    const duration = current.duration ? Math.floor(current.duration) : null;
    const signature = `${current.key}:${position}`;
    if (signature === lastSent) {
        return;
    }
    lastSent = signature;
    playedSinceSave = 0;
    items[current.key] = {
        position,
        duration,
        completed: Boolean(items[current.key]?.completed || (duration && position >= duration * 0.95)),
    };

    // keepalive — запрос дойдёт, даже если вкладку закрывают
    fetch(props.saveUrl, {
        method: 'POST',
        keepalive: true,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ video_key: current.key, position, duration }),
    }).catch(() => {});
}

function resumable(key) {
    const item = items[key];
    if (!item || item.completed || item.position < MIN_RESUME) {
        return null;
    }
    if (item.duration && item.position > item.duration - 5) {
        return null;
    }
    return item.position;
}

function showResume(position) {
    resumeAt.value = position;
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => (resumeAt.value = null), 15000);
}

function restore() {
    pendingRestore = false;
    const position = resumable(current.key);
    if (position === null) {
        return;
    }
    current.time = position;
    player.seekTo(position).catch(() => {});
    showResume(position);
}

function startOver() {
    resumeAt.value = null;
    current.time = 0;
    player?.seekTo(0).catch(() => {});
    player?.play().catch(() => {});
    save();
}

async function currentKey() {
    if (!props.playlistId) {
        return props.videoId;
    }
    const item = await player.getPlaylistItem().catch(() => null);
    return item?.id ?? null;
}

async function onLoaded({ data }) {
    current.duration = data?.duration || current.duration;
    current.key = await currentKey();

    if (!props.track) {
        return;
    }

    // В плейлисте возвращаем к ролику, который смотрели последним
    const last = props.progress?.last;
    if (props.playlistId && last && last !== current.key) {
        const position = resumable(last);
        current = { key: last, time: position ?? 0, duration: items[last]?.duration ?? null };
        player.switchTo(last, { time: position ?? 0 }).catch(() => {});
        if (position !== null) {
            showResume(position);
        }
        return;
    }

    restore();
}

function onTrackChanged({ data }) {
    const key = data?.item?.id;
    if (!key || key === current.key) {
        return;
    }
    save();
    current = { key, time: 0, duration: null };
    lastTick = null;
    pendingRestore = props.track;
}

function onDuration({ data }) {
    current.duration = data?.duration || current.duration;
    if (pendingRestore) {
        restore();
    }
}

function onTimeUpdate({ data }) {
    const time = data?.currentTime ?? 0;
    // считаем только непрерывный просмотр, без скачков перемотки
    if (lastTick !== null && time > lastTick && time - lastTick < 3) {
        playedSinceSave += time - lastTick;
    }
    lastTick = time;
    current.time = time;
    if (playedSinceSave >= SAVE_EVERY) {
        save();
    }
}

async function onSeeked() {
    current.time = await player.getCurrentTime().catch(() => current.time);
    lastTick = current.time;
    save();
}

function onEnded() {
    if (current.duration) {
        current.time = current.duration;
    }
    save();
}

function onHidden() {
    if (document.visibilityState === 'hidden') {
        save();
    }
}

onMounted(async () => {
    try {
        const factory = await loadKinescope();
        const mount = document.createElement('div');
        mount.id = `kinescope-${Math.random().toString(36).slice(2)}`;
        host.value.appendChild(mount);

        player = await factory.create(mount.id, {
            url: props.playlistId ? `https://kinescope.io/pl/${props.playlistId}` : `https://kinescope.io/${props.videoId}`,
            size: { width: '100%', height: '100%' },
            behavior: { preload: 'metadata', playsInline: true },
        });

        const events = player.Events;
        player.once(events.Loaded, onLoaded);
        player.on(events.CurrentTrackChanged, onTrackChanged);
        player.on(events.DurationChange, onDuration);
        player.on(events.TimeUpdate, onTimeUpdate);
        player.on(events.Pause, save);
        player.on(events.Seeked, onSeeked);
        player.on(events.Ended, onEnded);
        player.on(events.Playing, () => (lastTick = null));

        document.addEventListener('visibilitychange', onHidden);
        window.addEventListener('pagehide', save);
    } catch {
        failed.value = true;
    }
});

onBeforeUnmount(() => {
    clearTimeout(hideTimer);
    document.removeEventListener('visibilitychange', onHidden);
    window.removeEventListener('pagehide', save);
    if (player) {
        save();
        player.destroy().catch(() => {});
        player = null;
    }
});
</script>

<template>
    <div>
        <div class="relative aspect-video">
            <iframe
                v-if="failed"
                :src="embedUrl"
                class="absolute inset-0 h-full w-full"
                frameborder="0"
                allowfullscreen
                allow="autoplay; fullscreen; picture-in-picture; encrypted-media; gyroscope; accelerometer; clipboard-write; screen-wake-lock;"
            ></iframe>
            <div v-else ref="host" class="kinescope-host absolute inset-0"></div>
        </div>
        <div v-if="resumeAt !== null" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-white/10 px-5 py-3 text-sm font-semibold text-white" role="status">
            <span>Продолжаем с {{ formatTime(resumeAt) }}, где вы остановились</span>
            <button type="button" class="rounded-full bg-white/15 px-3.5 py-1.5 text-[13px] font-bold hover:bg-white/25 focus-visible:outline-2 focus-visible:outline-white" @click="startOver">
                Смотреть сначала
            </button>
            <button type="button" class="ml-auto rounded-full px-2 py-1 text-white/70 hover:text-white" aria-label="Скрыть подсказку" @click="resumeAt = null">✕</button>
        </div>
    </div>
</template>

<style scoped>
.kinescope-host :deep(iframe) {
    width: 100%;
    height: 100%;
    border: 0;
}
</style>
