<?php

namespace App\Http\Controllers;

use App\Models\MedicalLibrary;
use App\Services\CabinetEvents;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Сводка личного кабинета.
     */
    public function index(Request $request, CabinetEvents $cabinetEvents): Response
    {
        $user = $request->user();
        $events = $cabinetEvents->all($user);
        $today = now()->toDateString();

        $live = $events->firstWhere('is_live', true);

        $upcoming = $events
            ->filter(fn (array $event): bool => ! $event['is_live'] && ! $event['is_past'])
            ->sortBy(fn (array $event): string => ($event['start_date'] ?? '9999-12-31').' '.($event['start_time'] ?? ''))
            ->take(6)
            ->values();

        $records = $events
            ->filter(fn (array $event): bool => $event['is_past'] && $event['has_recording'])
            ->sortByDesc('start_date')
            ->take(6)
            ->values();

        $calendar = $events
            ->filter(fn (array $event): bool => $event['start_date'] !== null)
            ->map(fn (array $event): array => [
                'date' => $event['start_date'],
                'time' => $event['start_time'],
                'title' => $event['title'],
                'slug' => $event['slug'],
                'is_live' => $event['is_live'],
                'format' => $event['format'],
            ])
            ->values();

        return Inertia::render('Dashboard', [
            'live' => $live,
            'upcoming' => $upcoming,
            'records' => $records,
            'calendar' => $calendar,
            'today' => $today,
            'library' => [
                'total' => MedicalLibrary::query()->count(),
                'latest' => MedicalLibrary::query()
                    ->latest('publication_date')
                    ->limit(3)
                    ->get(['id', 'title', 'publication_date', 'language']),
            ],
            'setup' => [
                'email_verified' => $user->hasVerifiedEmail(),
                'phone_verified' => $user->phone_verified_at !== null,
                'profile_filled' => filled($user->specialization) && filled($user->city),
            ],
        ]);
    }

    /**
     * Все мероприятия пользователя: предстоящие, записи и архив.
     */
    public function myEvents(Request $request, CabinetEvents $cabinetEvents): Response
    {
        return Inertia::render('MyEvents', [
            'events' => $cabinetEvents->all($request->user()),
        ]);
    }
}
