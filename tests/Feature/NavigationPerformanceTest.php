<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('public navigation does not load unused event statistics for signed in users', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $user->events()->attach($event, ['is_active' => true, 'access_type' => 'free']);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->actingAs($user)->get('/contacts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Contacts')
        ->where('auth.user.id', $user->id)
        ->has('auth.user.roles')
        ->has('auth.user.profile_missing')
        ->where('auth.user.unread_messages', 0)
        ->missing('auth.user.stats'));

    $eventQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"events"'));
    DB::disableQueryLog();

    expect($eventQueries->all())->toBeEmpty();
});

test('partial reloads skip user queries when auth is not requested', function () {
    $user = User::factory()->create();
    $version = $this->actingAs($user)->get('/contacts')->viewData('page')['version'];

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->actingAs($user)->get('/contacts', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Contacts',
        'X-Inertia-Partial-Data' => 'flash',
    ])->assertOk()->assertJsonMissingPath('props.auth');

    $profileQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"notifications"') || str_contains($query['query'], '"roles"') || str_contains($query['query'], '"events"')
    );
    DB::disableQueryLog();

    expect($profileQueries->all())->toBeEmpty();
});

test('guest navigation keeps auth user null', function () {
    $this->get('/contacts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Contacts')
        ->where('auth.user', null));
});
