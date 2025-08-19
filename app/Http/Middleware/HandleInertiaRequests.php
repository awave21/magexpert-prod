<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'csrf' => [
                'token' => csrf_token(),
            ],
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'first_name' => $request->user()->first_name,
                    'last_name' => $request->user()->last_name,
                    'middle_name' => $request->user()->middle_name,
                    'full_name' => $request->user()->getFullNameAttribute(),
                    'email' => $request->user()->email,
                    'avatar' => $request->user()->avatar,
                    'phone' => $request->user()->phone,
                    'company' => $request->user()->company,
                    'position' => $request->user()->position,
                    'city' => $request->user()->city,
                    'permissions' => $request->user()->permissions ?? [],
                    'roles' => $request->user()->roles()->get(),
                    'stats' => $this->getUserStats($request->user()),
                ] : null,
            ],
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ]);
    }

    /**
     * Получить статистику пользователя
     *
     * @param \App\Models\User $user
     * @return array
     */
    private function getUserStats($user): array
    {
        $liveEventsCount = $user->liveEvents->count();
        $upcomingEventsCount = $user->upcomingEvents->count();
        $totalAccessibleEvents = $user->accessibleEvents()->count();
        $archivedEvents = $user->accessibleEvents()
            ->where('events.is_archived', true)
            ->count();

        return [
            'availableEvents' => $totalAccessibleEvents,
            'liveEvents' => $liveEventsCount,
            'upcomingEvents' => $upcomingEventsCount,
            'archive' => $archivedEvents,
        ];
    }
}
