<?php

use App\Models\Event;
use App\Models\Role;
use App\Models\User;

function dashboardAdmin(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

    return $user;
}

test('главная админки показывает показатели, ближайшие мероприятия и последние доступы', function () {
    $admin = dashboardAdmin();
    $event = Event::factory()->create([
        'title' => 'Школа эстетической гинекологии',
        'is_active' => true,
        'is_archived' => false,
        'start_date' => now()->addDays(5)->toDateString(),
    ]);
    $event->users()->attach($admin->id, [
        'access_type' => 'paid',
        'payment_status' => 'completed',
        'payment_amount' => 5000,
        'access_granted_at' => now(),
        'is_active' => true,
    ]);

    $this->actingAs($admin)->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Index')
            ->where('overview.registrations.current', 1)
            ->where('overview.paid.current', 5000)
            ->where('overview.events_upcoming', 1)
            ->has('upcoming', 1)
            ->where('upcoming.0.title', 'Школа эстетической гинекологии')
            ->where('upcoming.0.registrations', 1)
            ->has('latestRegistrations', 1)
            ->where('latestRegistrations.0.event_title', 'Школа эстетической гинекологии'));
});

test('архивные и скрытые мероприятия в ближайших не показываются', function () {
    Event::factory()->create(['is_active' => false, 'start_date' => now()->addDay()->toDateString()]);
    Event::factory()->create(['is_archived' => true, 'start_date' => now()->addDay()->toDateString()]);

    $this->actingAs(dashboardAdmin())->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('upcoming', 0)->where('overview.events_upcoming', 0));
});
