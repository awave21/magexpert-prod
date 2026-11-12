<?php

use App\Models\Event;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the nearest event regardless of registration availability', function (bool $registrationEnabled) {
    $this->travelTo(now()->startOfDay());

    $nearest = Event::factory()->create([
        'start_date' => today()->addDays(4),
        'start_time' => '15:00:00',
        'registration_enabled' => $registrationEnabled,
    ]);

    Event::factory()->create([
        'start_date' => $nearest->start_date,
        'start_time' => '18:00:00',
    ]);
    Event::factory()->create(['start_date' => today()->addDays(5)]);
    Event::factory()->create(['start_date' => today()->subYear(), 'is_on_demand' => true]);
    Event::factory()->create(['start_date' => today()->addDay(), 'is_active' => false]);
    Event::factory()->create(['start_date' => today()->addDays(2), 'is_archived' => true]);
    Event::factory()->create(['start_date' => today()->subDay(), 'is_on_demand' => false]);

    $this->get('/')->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->where('upcomingEvent.id', $nearest->id)
        ->where('upcomingEvent.slug', $nearest->slug)
    );
})->with([true, false]);

it('shows no upcoming event when only hidden archived or past events exist', function () {
    Event::factory()->create(['is_active' => false]);
    Event::factory()->create(['is_archived' => true]);
    Event::factory()->create(['start_date' => today()->subDay(), 'is_on_demand' => false]);

    $this->get('/')->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->where('upcomingEvent', null)
    );
});
