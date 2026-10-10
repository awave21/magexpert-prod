<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\SenderAddressRequest;
use App\Sender\Models\SenderAddress;
use App\Sender\Services\SenderAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class SenderAddressController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): JsonResponse
    {
        $addresses = $this->organization($request)->senderAddresses()->with('domain')->orderBy('email')->get();

        return response()->json(['data' => $addresses->map(fn (SenderAddress $a): array => $this->present($a))]);
    }

    /**
     * Новый адрес сразу получает письмо со ссылкой подтверждения.
     */
    public function store(SenderAddressRequest $request, SenderAddressService $addresses): JsonResponse
    {
        $organization = $this->organization($request);
        $email = $request->validated('email');
        $domain = $organization->domains()->where('domain', Str::after($email, '@'))->firstOrFail();

        $result = $addresses->create($organization, $domain, $email, $request->validated('name'));

        return response()->json([
            'data' => $this->present($result['address']->load('domain')),
            'warning' => $result['sent'] ? null : 'Адрес добавлен, но письмо со ссылкой отправить не удалось. Проверьте настройки почты сервера и нажмите «Отправить ещё раз».',
        ], 201);
    }

    public function resend(Request $request, int $address, SenderAddressService $addresses): JsonResponse
    {
        $model = $this->organization($request)->senderAddresses()->with('domain')->findOrFail($address);

        if ($model->isConfirmed()) {
            return response()->json(['message' => 'Адрес уже подтверждён'], 422);
        }

        if (! $addresses->canResend($model)) {
            return response()->json(['message' => 'Письмо только что отправлено, повторить можно через минуту'], 429);
        }

        if ($addresses->trySendConfirmation($model) !== null) {
            return response()->json(['message' => 'Письмо отправить не удалось: почтовый сервер не принял его. Попробуйте позже или проверьте настройки почты сервера.'], 503);
        }

        return response()->json(['data' => $this->present($model->fresh('domain'))]);
    }

    /**
     * Меняется только имя: адрес уже используется в шаблонах.
     */
    public function update(SenderAddressRequest $request, int $address): JsonResponse
    {
        $model = $this->organization($request)->senderAddresses()->with('domain')->findOrFail($address);
        $model->update(['name' => $request->validated('name')]);

        return response()->json(['data' => $this->present($model)]);
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $this->organization($request)->senderAddresses()->findOrFail($address)->delete();

        return response()->json(['message' => 'Адрес удалён']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SenderAddress $address): array
    {
        return [
            'id' => $address->id,
            'email' => $address->email,
            'name' => $address->name,
            'domain' => $address->domain?->domain,
            'verified' => (bool) $address->domain?->isVerified(),
            'confirmed' => $address->isConfirmed(),
            'confirmation_sent_at' => $address->confirmation_sent_at?->toIso8601String(),
        ];
    }
}
