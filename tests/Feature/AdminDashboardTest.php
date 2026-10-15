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

test('аналитика главной считает регистрации, просмотры записей и вопросы помощнику', function () {
    $admin = dashboardAdmin();
    $event = Event::factory()->create(['title' => 'Школа', 'start_date' => now()->addDays(3)->toDateString()]);
    $viewer = User::factory()->create();

    $event->users()->attach($viewer->id, [
        'access_type' => 'free', 'payment_status' => 'free', 'access_granted_at' => now(), 'is_active' => true,
    ]);
    DB::table('video_progress')->insert([
        ['user_id' => $viewer->id, 'event_id' => $event->id, 'video_key' => 'abc', 'position' => 300, 'duration' => 600, 'completed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $admin->id, 'event_id' => $event->id, 'video_key' => 'abc', 'position' => 600, 'duration' => 600, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('assistant_questions')->insert([
        'user_id' => $viewer->id, 'question' => 'Как вводить препарат?', 'found' => false,
        'sources' => '[]', 'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1, 'duration_ms' => 2000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($admin)->get('/admin?period=30')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('analytics.period', 30)
            ->where('analytics.kpis.registrations.current', 1)
            ->where('analytics.kpis.video_viewers.current', 2)
            ->where('analytics.kpis.questions.current', 1)
            ->where('analytics.video.summary.avg_depth', 75)
            ->where('analytics.video.summary.completed_rate', 50)
            ->where('analytics.video.top.0.viewers', 2)
            ->where('analytics.assistant.summary.found_rate', 0)
            ->where('analytics.assistant.missing.0.question', 'Как вводить препарат?')
            ->has('analytics.activity.labels', 30)
            ->has('analytics.heatmap', 7));
});

test('неизвестный период заменяется на 30 дней', function () {
    $this->actingAs(dashboardAdmin())->get('/admin?period=13')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('analytics.period', 30));
});
