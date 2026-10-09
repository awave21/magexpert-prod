<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SenderMailService;
use App\Services\SendsayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserController extends Controller
{
    protected SendsayService $sendsayService;

    public function __construct(SendsayService $sendsayService)
    {
        $this->sendsayService = $sendsayService;
    }

    public function store(Request $request): JsonResponse
    {
        // Валидация входящих данных
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'privacy_consent' => 'required|boolean|accepted',
            'oferta_consent' => 'required|boolean|accepted',
            'newsletter_consent' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Генерация пароля (12 символов)
            $generatedPassword = Str::random(12);

            // Создание пользователя
            $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'middle_name' => $request->middle_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'position' => $request->position,
                'specialization' => $request->specialization,
                'company' => $request->company,
                'city' => $request->city,
                'password' => Hash::make($generatedPassword),
                'privacy_consent' => $request->privacy_consent,
                'oferta_consent' => $request->oferta_consent,
                'newsletter_consent' => $request->newsletter_consent ?? false,
                'email_verified_at' => now(),
            ]);

            // Отправка письма с паролем
            if ($request->newsletter_consent) {
                $this->sendWelcomeEmailWithPassword($user, $generatedPassword);
            }

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $user->only([
                    'id', 'first_name', 'last_name', 'middle_name',
                    'email', 'phone', 'position', 'specialization',
                    'company', 'city', 'privacy_consent', 'oferta_consent',
                    'newsletter_consent', 'created_at',
                ]),
            ], 201);

        } catch (\Exception $e) {
            \Log::error('API user creation failed', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Отправка приветственного письма с паролем
     */
    protected function sendWelcomeEmailWithPassword(User $user, string $password): void
    {
        $fullName = trim($user->first_name.' '.$user->last_name);

        try {
            // Используем специальный метод для API регистрации
            app(SenderMailService::class)->sendApiRegistrationEmail(
                $user->email,
                $password,
                $fullName
            );

            \Log::info('API registration email with password sent', [
                'email' => $user->email,
                'user_id' => $user->id,
            ]);

        } catch (\Exception $e) {
            \Log::warning('Failed to send API registration email with password', [
                'email' => $user->email,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
