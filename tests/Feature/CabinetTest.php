<?php

use App\Models\Event;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function grantEvent(User $user, Event $event, string $status = 'free'): void
{
    $event->users()->attach($user->id, [
        'access_type' => $status === 'free' ? 'free' : 'paid',
        'payment_status' => $status,
        'access_granted_at' => now(),
        'is_active' => true,
    ]);
}

test('сводка показывает предстоящие мероприятия, календарь и что осталось настроить', function () {
    $user = User::factory()->create(['email_verified_at' => null, 'specialization' => 'Гинеколог', 'city' => 'Москва']);
    $upcoming = Event::factory()->create(['title' => 'Вебинар по контурной пластике']);
    $pending = Event::factory()->paid()->create(['title' => 'Платный круглый стол']);
    grantEvent($user, $upcoming);
    grantEvent($user, $pending, 'pending');

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('upcoming', 2)
        ->where('upcoming.0.title', 'Вебинар по контурной пластике')
        ->where('upcoming.0.access', 'free')
        ->where('upcoming.1.access', 'pending')
        ->has('calendar', 2)
        ->where('setup.email_verified', false)
        ->where('setup.phone_verified', false)
        ->where('setup.profile_filled', true));
});

test('мероприятия делятся на предстоящие и записи', function () {
    $user = User::factory()->create();
    grantEvent($user, Event::factory()->create());
    grantEvent($user, Event::factory()->past()->create(['kinescope_id' => 'abc', 'kinescope_type' => 'video']));

    $this->actingAs($user)->get('/my-events')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('MyEvents')
        ->has('events', 2)
        ->where('events.1.is_past', true)
        ->where('events.1.has_recording', true));
});

test('страница входа и безопасности открывается', function () {
    $user = User::factory()->create(['phone' => '+7 (999) 000-00-00']);

    $this->actingAs($user)->get('/profile/security')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Cabinet/Security')
        ->where('phone', '+7 (999) 000-00-00')
        ->where('phoneVerified', false)
        ->has('social'));
});

test('настройки уведомлений сохраняются', function () {
    $user = User::factory()->create(['newsletter_consent' => false]);

    $this->actingAs($user)->get('/profile/notifications')->assertInertia(fn (Assert $page) => $page
        ->component('Cabinet/Notifications')
        ->where('settings.newsletter_consent', false)
        ->where('settings.site_notifications', true));

    $this->actingAs($user)
        ->patch('/profile/notifications', ['newsletter_consent' => true, 'site_notifications' => false])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($user->refresh())
        ->newsletter_consent->toBeTrue()
        ->site_notifications->toBeFalse();
});

test('настройки уведомлений требуют оба переключателя', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile/notifications', ['newsletter_consent' => true])
        ->assertSessionHasErrors('site_notifications');
});

test('в платежах только свои оплаты', function () {
    $user = User::factory()->create();
    Payment::factory()->create(['user_id' => $user->id]);
    Payment::factory()->create(['user_id' => $user->id, 'status' => Payment::STATUS_PENDING, 'paid_at' => null]);
    Payment::factory()->create();

    $this->actingAs($user)->get('/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Cabinet/Payments')
        ->has('payments', 2));
});

test('колокольчик отдаёт свои сообщения и отмечает прочитанными', function () {
    $user = User::factory()->create();
    $mine = Notification::createNotification(['user_id' => $user->id, 'type' => 'info', 'title' => 'Мастер-класс 24 октября', 'message' => 'Начало в 10:00']);
    $other = Notification::createNotification(['user_id' => User::factory()->create()->id, 'type' => 'info', 'title' => 'Чужое', 'message' => '—']);

    $this->actingAs($user)->getJson('/cabinet/messages')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('unread', 1);

    $this->actingAs($user)->postJson("/cabinet/messages/{$other->id}/read")->assertNotFound();
    $this->actingAs($user)->postJson("/cabinet/messages/{$mine->id}/read")->assertOk();
    expect($mine->refresh()->read)->toBeTrue();

    Notification::createNotification(['user_id' => $user->id, 'type' => 'info', 'title' => 'Ещё', 'message' => '—']);
    $this->actingAs($user)->postJson('/cabinet/messages/read-all')->assertOk();
    expect($user->notifications()->where('read', false)->count())->toBe(0);
});

test('кабинет закрыт для гостей', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/dashboard', '/my-events', '/profile/security', '/profile/notifications', '/payments', '/cabinet/messages']);
