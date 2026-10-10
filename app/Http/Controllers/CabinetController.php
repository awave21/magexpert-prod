<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationSettingsRequest;
use App\Models\Payment;
use App\Services\SocialAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Разделы личного кабинета, которых не было в старом профиле: вход и безопасность, уведомления, платежи.
 */
class CabinetController extends Controller
{
    public function security(Request $request): Response
    {
        $user = $request->user();
        $linked = $user->socialAccounts()->get(['provider', 'email'])->keyBy('provider');

        return Inertia::render('Cabinet/Security', [
            'social' => collect(SocialAccounts::NAMES)->map(fn (string $name, string $key): array => [
                'key' => $key,
                'name' => $name,
                'linked' => $linked->has($key),
                'email' => $linked->get($key)?->email,
                'available' => (bool) config("services.{$key}.client_id"),
            ])->values(),
            'phone' => $user->phone,
            'phoneVerified' => $user->phone_verified_at !== null,
        ]);
    }

    public function notifications(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Cabinet/Notifications', [
            'settings' => [
                'newsletter_consent' => (bool) $user->newsletter_consent,
                'site_notifications' => (bool) $user->site_notifications,
            ],
        ]);
    }

    public function updateNotifications(NotificationSettingsRequest $request): RedirectResponse
    {
        $request->user()->forceFill($request->validated())->save();

        return back()->with('message', 'Настройки уведомлений сохранены');
    }

    public function payments(Request $request): Response
    {
        $payments = Payment::query()
            ->where('user_id', $request->user()->id)
            ->with('event:id,title,slug,start_date,format,event_type')
            ->latest()
            ->get()
            ->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'date' => ($payment->paid_at ?? $payment->created_at)?->toDateString(),
                'title' => $payment->event?->title ?? ($payment->metadata['event_title'] ?? $payment->description),
                'slug' => $payment->event?->slug,
                'format' => $payment->event?->format,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency ?: 'RUB',
                'status' => $payment->status,
            ]);

        return Inertia::render('Cabinet/Payments', [
            'payments' => $payments,
        ]);
    }
}
