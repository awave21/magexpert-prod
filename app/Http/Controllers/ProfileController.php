<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\AccountAnonymizer;
use App\Services\SocialAccounts;
use App\Traits\ManagesAvatars;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    use ManagesAvatars;

    /**
     * Display the user's profile.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Profile/Show', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $linked = $request->user()->socialAccounts()->pluck('provider')->all();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'social' => collect(SocialAccounts::NAMES)->map(fn (string $name, string $key): array => [
                'key' => $key,
                'name' => $name,
                'linked' => in_array($key, $linked, true),
                'available' => (bool) config("services.{$key}.client_id"),
            ])->values(),
            'phoneVerified' => $request->user()->phone_verified_at !== null,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        // Данные для обновления
        $userData = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'email' => $validated['email'],
            'specialization' => $validated['specialization'] ?? null,
            'city' => $validated['city'] ?? null,
            'phone' => $validated['phone'] ?? null,
        ];

        // новый email нужно подтвердить заново
        if (mb_strtolower($userData['email']) !== mb_strtolower((string) $user->email)) {
            $userData['email_verified_at'] = null;
        }

        // новый номер нужно подтвердить заново
        $accounts = app(SocialAccounts::class);
        if ($accounts->normalize($userData['phone']) !== $accounts->normalize($user->phone)) {
            $userData['phone_verified_at'] = null;
        }

        try {
            // Обработка аватара
            if ($request->hasFile('avatar')) {
                $this->processAndSaveAvatar($user, $request->file('avatar'));
            } elseif ($request->boolean('delete_avatar')) {
                if ($user->avatar && ! str_starts_with($user->avatar, 'http')) {
                    $oldAvatarPath = str_replace('/storage/', '', $user->avatar);
                    if (Storage::disk('public')->exists($oldAvatarPath)) {
                        Storage::disk('public')->delete($oldAvatarPath);
                    }
                }
                $userData['avatar'] = null;
            }

            $user->forceFill($userData)->save();

            return Redirect::route('profile.edit')->with('message', 'Профиль сохранён');
        } catch (\Exception $e) {
            return Redirect::route('profile.edit')->with('error', 'Произошла ошибка при обновлении профиля');
        }
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        app(AccountAnonymizer::class)->anonymize($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/')->with('message', 'Аккаунт удалён. Ваши личные данные стёрты.');
    }
}
