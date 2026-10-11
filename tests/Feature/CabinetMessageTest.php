<?php

use App\Models\CabinetBroadcast;
use App\Models\Event;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;

function staff(string $role = 'admin'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

    return $user;
}

test('врач не может открыть отправку сообщений', function () {
    $this->actingAs(User::factory()->create())->get('/admin/messages')->assertForbidden();
    $this->actingAs(staff('editor'))->get('/admin/messages')->assertForbidden();
});

test('админ видит форму и историю', function () {
    $this->actingAs(staff())->get('/admin/messages')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Messages')
        ->has('events')
        ->has('history.data'));
});

test('сообщение всем уходит каждому живому аккаунту, удалённым — нет', function () {
    $admin = staff();
    User::factory()->count(2)->create();
    User::factory()->create(['email' => 'deleted-99@mag-expert.invalid']);

    $this->actingAs($admin)->post('/admin/messages', [
        'audience' => 'all',
        'title' => 'Мастер-класс 24 октября',
        'message' => 'Начало в 10:00 МСК',
        'url' => '/my-events',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $broadcast = CabinetBroadcast::first();
    expect($broadcast->recipients_count)->toBe(3)
        ->and($broadcast->sent_by)->toBe($admin->id)
        ->and(Notification::count())->toBe(3)
        ->and(Notification::first()->only(['title', 'message', 'url', 'read']))
        ->toBe(['title' => 'Мастер-класс 24 октября', 'message' => 'Начало в 10:00 МСК', 'url' => '/my-events', 'read' => false]);
});

test('сообщение участникам мероприятия уходит только им', function () {
    $event = Event::factory()->create();
    $member = User::factory()->create();
    $event->users()->attach($member->id, ['access_type' => 'free', 'payment_status' => 'free', 'is_active' => true]);
    $inactive = User::factory()->create();
    $event->users()->attach($inactive->id, ['access_type' => 'free', 'payment_status' => 'free', 'is_active' => false]);
    User::factory()->create();

    $admin = staff('manager');
    $this->actingAs($admin)->getJson("/admin/messages/count?audience=event&event_id={$event->id}")->assertJson(['count' => 1]);

    $this->actingAs($admin)->post('/admin/messages', [
        'audience' => 'event', 'event_id' => $event->id, 'title' => 'Запись готова', 'message' => 'Смотрите в разделе «Мероприятия»',
    ])->assertSessionHasNoErrors();

    expect(Notification::pluck('user_id')->all())->toBe([$member->id]);
});

test('сообщение одному пользователю по email', function () {
    $doctor = User::factory()->create(['email' => 'anna@example.com']);

    $this->actingAs(staff())->post('/admin/messages', [
        'audience' => 'user', 'email' => 'ANNA@example.com', 'title' => 'Здравствуйте', 'message' => 'Проверьте email',
    ])->assertSessionHasNoErrors();

    expect(Notification::pluck('user_id')->all())->toBe([$doctor->id]);
});

test('ссылка только на страницы сайта', function (string $url) {
    $this->actingAs(staff())->post('/admin/messages', [
        'audience' => 'all', 'title' => 'Тест', 'message' => 'Тест', 'url' => $url,
    ])->assertSessionHasErrors('url');

    expect(Notification::count())->toBe(0);
})->with(['https://evil.example', '//evil.example', 'javascript:alert(1)']);

test('без мероприятия и email отправить нельзя', function () {
    $this->actingAs(staff())
        ->post('/admin/messages', ['audience' => 'event', 'title' => 'Тест', 'message' => 'Тест'])
        ->assertSessionHasErrors('event_id');
    $this->actingAs(staff())
        ->post('/admin/messages', ['audience' => 'user', 'email' => 'nobody@example.com', 'title' => 'Тест', 'message' => 'Тест'])
        ->assertSessionHasErrors('email');
});
