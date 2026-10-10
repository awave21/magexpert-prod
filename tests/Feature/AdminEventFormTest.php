<?php

use App\Models\Event;
use App\Models\Role;
use App\Models\User;

function eventEditor(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::firstOrCreate(['name' => 'editor']));

    return $user;
}

function eventPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Мастер-класс по инъекционным методикам',
        'event_type' => 'workshop',
        'format' => 'online',
        'start_date' => now()->addWeek()->toDateString(),
        'start_time' => '10:00',
        'is_paid' => false,
        'show_price' => false,
        'is_on_demand' => false,
        'registration_enabled' => true,
        'is_active' => true,
        'is_archived' => false,
        'is_live' => false,
        'sort_order' => 0,
        'kinescope_type' => 'video',
        'kinescope_id' => 'abc123',
    ], $overrides);
}

test('бесплатное мероприятие создаётся с видео', function () {
    $this->actingAs(eventEditor())->post('/admin/events', eventPayload())
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(Event::first())
        ->title->toBe('Мастер-класс по инъекционным методикам')
        ->kinescope_id->toBe('abc123')
        ->is_paid->toBeFalse();
});

test('платное мероприятие без цены не сохраняется', function () {
    $this->actingAs(eventEditor())->post('/admin/events', eventPayload(['is_paid' => true, 'price' => null]))
        ->assertSessionHasErrors(['price' => 'Укажите стоимость: без неё платное мероприятие нельзя оплатить.']);

    expect(Event::count())->toBe(0);
});

test('платное мероприятие с ценой сохраняется', function () {
    $this->actingAs(eventEditor())->post('/admin/events', eventPayload(['is_paid' => true, 'price' => 5000, 'show_price' => true]))
        ->assertSessionHasNoErrors();

    expect((float) Event::first()->price)->toBe(5000.0);
});

test('мероприятие «в любое время» не требует даты', function () {
    $this->actingAs(eventEditor())->post('/admin/events', eventPayload(['is_on_demand' => true, 'start_date' => null, 'start_time' => null]))
        ->assertSessionHasNoErrors();

    expect(Event::first()->is_on_demand)->toBeTrue();
});

test('без даты обычное мероприятие не сохраняется, ошибка по-русски', function () {
    $this->actingAs(eventEditor())->post('/admin/events', eventPayload(['start_date' => null]))
        ->assertSessionHasErrors(['start_date' => 'Укажите дату начала или отметьте «Без даты — смотреть в любое время».']);
});

test('редактирование платного без цены тоже требует цену', function () {
    $event = Event::factory()->paid()->create();
    $event->update(['price' => null]);

    $this->actingAs(eventEditor())->post("/admin/events/{$event->id}", eventPayload(['_method' => 'PUT', 'is_paid' => true, 'price' => null]))
        ->assertSessionHasErrors('price');
});
