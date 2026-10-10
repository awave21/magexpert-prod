<?php

use App\Models\Event;
use App\Models\User;
use App\Models\VideoProgress;
use Inertia\Testing\AssertableInertia as Assert;

function openRecording(User $user, Event $event, string $status = 'free'): void
{
    $event->users()->attach($user->id, [
        'access_type' => $status === 'free' ? 'free' : 'paid',
        'payment_status' => $status,
        'access_granted_at' => now(),
        'is_active' => true,
    ]);
}

function recording(array $attributes = []): Event
{
    return Event::factory()->past()->create(['kinescope_type' => 'video', 'kinescope_id' => 'abc123XYZ', ...$attributes]);
}

test('позиция просмотра сохраняется и обновляется одной строкой', function () {
    $user = User::factory()->create();
    $event = recording();
    openRecording($user, $event);

    $this->actingAs($user)
        ->postJson(route('my-events.progress', $event->slug), ['video_key' => 'abc123XYZ', 'position' => 125, 'duration' => 3600])
        ->assertNoContent();
    $this->actingAs($user)
        ->postJson(route('my-events.progress', $event->slug), ['video_key' => 'abc123XYZ', 'position' => 430, 'duration' => 3600])
        ->assertNoContent();

    expect(VideoProgress::query()->where('user_id', $user->id)->count())->toBe(1);
    $progress = VideoProgress::query()->first();
    expect($progress->position)->toBe(430)
        ->and($progress->duration)->toBe(3600)
        ->and($progress->completed_at)->toBeNull();
});

test('запись считается досмотренной после 95 процентов и остаётся такой при пересмотре', function () {
    $user = User::factory()->create();
    $event = recording();
    openRecording($user, $event);
    $url = route('my-events.progress', $event->slug);

    $this->actingAs($user)->postJson($url, ['video_key' => 'abc123XYZ', 'position' => 3450, 'duration' => 3600])->assertNoContent();
    expect(VideoProgress::query()->first()->completed_at)->not->toBeNull();

    $this->actingAs($user)->postJson($url, ['video_key' => 'abc123XYZ', 'position' => 60, 'duration' => 3600])->assertNoContent();
    $progress = VideoProgress::query()->first();
    expect($progress->position)->toBe(60)->and($progress->completed_at)->not->toBeNull();
});

test('позиция не может быть больше длительности', function () {
    $user = User::factory()->create();
    $event = recording();
    openRecording($user, $event);

    $this->actingAs($user)
        ->postJson(route('my-events.progress', $event->slug), ['video_key' => 'abc123XYZ', 'position' => 5000, 'duration' => 3600])
        ->assertNoContent();

    expect(VideoProgress::query()->first()->position)->toBe(3600);
});

test('без доступа к мероприятию позицию сохранить нельзя', function () {
    $user = User::factory()->create();
    $event = recording();

    $this->actingAs($user)
        ->postJson(route('my-events.progress', $event->slug), ['video_key' => 'abc123XYZ', 'position' => 10])
        ->assertForbidden();

    expect(VideoProgress::query()->count())->toBe(0);
});

test('платная запись без оплаты не сохраняет позицию', function () {
    $user = User::factory()->create();
    $event = recording(['is_paid' => true, 'price' => 5000]);
    openRecording($user, $event, 'pending');

    $this->actingAs($user)
        ->postJson(route('my-events.progress', $event->slug), ['video_key' => 'abc123XYZ', 'position' => 10])
        ->assertForbidden();
});

test('гость не может сохранить позицию', function () {
    $event = recording();

    $this->postJson(route('my-events.progress', $event->slug), ['video_key' => 'abc123XYZ', 'position' => 10])
        ->assertUnauthorized();
});

test('проверяет данные позиции', function (array $payload, string $field) {
    $user = User::factory()->create();
    $event = recording();
    openRecording($user, $event);

    $this->actingAs($user)
        ->postJson(route('my-events.progress', $event->slug), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'нет ID видео' => [['position' => 10], 'video_key'],
    'ID со спецсимволами' => [['video_key' => '../etc', 'position' => 10], 'video_key'],
    'отрицательная позиция' => [['video_key' => 'abc', 'position' => -1], 'position'],
    'нулевая длительность' => [['video_key' => 'abc', 'position' => 1, 'duration' => 0], 'duration'],
]);

test('страница записи передаёт плееру позиции и последний ролик плейлиста', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $event = recording(['kinescope_type' => 'playlist', 'kinescope_id' => null, 'kinescope_playlist_id' => 'pl777']);
    openRecording($user, $event);
    VideoProgress::factory()->create(['user_id' => $user->id, 'event_id' => $event->id, 'video_key' => 'first', 'position' => 900, 'updated_at' => now()->subDay()]);
    VideoProgress::factory()->completed()->create(['user_id' => $user->id, 'event_id' => $event->id, 'video_key' => 'second', 'updated_at' => now()]);
    VideoProgress::factory()->create(['user_id' => $other->id, 'event_id' => $event->id, 'video_key' => 'foreign']);

    $this->actingAs($user)->get(route('my-events.view', $event->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/View')
            ->where('progress.last', 'second')
            ->where('progress.items.first.position', 900)
            ->where('progress.items.first.completed', false)
            ->where('progress.items.second.completed', true)
            ->missing('progress.items.foreign')
            ->where('progressUrl', route('my-events.progress', $event->slug)));
});

test('без просмотров плеер получает пустой прогресс', function () {
    $user = User::factory()->create();
    $event = recording();
    openRecording($user, $event);

    $this->actingAs($user)->get(route('my-events.view', $event->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('progress.last', null)
            ->where('progress.items', []));
});

test('сводка показывает недосмотренные записи с долей просмотренного', function () {
    $user = User::factory()->create();
    $started = recording(['title' => 'Разбор клинических случаев']);
    $finished = recording(['title' => 'Досмотренный вебинар']);
    $justOpened = recording(['title' => 'Только открыл']);
    $noAccess = recording(['title' => 'Чужая запись']);
    foreach ([$started, $finished, $justOpened] as $event) {
        openRecording($user, $event);
    }
    VideoProgress::factory()->create(['user_id' => $user->id, 'event_id' => $started->id, 'position' => 900, 'duration' => 3600]);
    VideoProgress::factory()->completed()->create(['user_id' => $user->id, 'event_id' => $finished->id]);
    VideoProgress::factory()->create(['user_id' => $user->id, 'event_id' => $justOpened->id, 'position' => 5, 'duration' => 3600]);
    VideoProgress::factory()->create(['user_id' => $user->id, 'event_id' => $noAccess->id, 'position' => 600]);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('watching', 1)
            ->where('watching.0.title', 'Разбор клинических случаев')
            ->where('watching.0.position', 900)
            ->where('watching.0.percent', 25));
});

test('в плейлисте на сводке одна карточка — по последнему ролику', function () {
    $user = User::factory()->create();
    $event = recording(['kinescope_type' => 'playlist', 'kinescope_id' => null, 'kinescope_playlist_id' => 'pl1']);
    openRecording($user, $event);
    VideoProgress::factory()->create(['user_id' => $user->id, 'event_id' => $event->id, 'video_key' => 'a', 'position' => 100, 'duration' => 1000, 'updated_at' => now()->subHour()]);
    VideoProgress::factory()->create(['user_id' => $user->id, 'event_id' => $event->id, 'video_key' => 'b', 'position' => 500, 'duration' => 1000, 'updated_at' => now()]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->has('watching', 1)
            ->where('watching.0.position', 500)
            ->where('watching.0.percent', 50));
});
