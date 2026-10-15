<?php

use App\Models\Event;
use App\Models\Role;
use App\Models\User;

function searchEditor(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::firstOrCreate(['name' => 'editor']));

    return $user;
}

test('поиск мероприятий не зависит от регистра букв', function () {
    Event::factory()->create(['title' => 'Тестовое мероприятие', 'is_active' => true]);

    $this->actingAs(searchEditor())
        ->getJson('/admin/events/search?q='.urlencode('тест'))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.text', 'Тестовое мероприятие');
});

test('скрытые мероприятия в поиске не показываются', function () {
    Event::factory()->create(['title' => 'Скрытый вебинар', 'is_active' => false]);

    $this->actingAs(searchEditor())
        ->getJson('/admin/events/search?q='.urlencode('скрытый'))
        ->assertOk()
        ->assertJsonCount(0);
});

test('пустой запрос возвращает пустой список', function () {
    $this->actingAs(searchEditor())
        ->getJson('/admin/events/search?q=')
        ->assertOk()
        ->assertJsonCount(0);
});
