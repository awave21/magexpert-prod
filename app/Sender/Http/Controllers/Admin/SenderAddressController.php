<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\SenderAddressRequest;
use App\Sender\Models\SenderAddress;
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

    public function store(SenderAddressRequest $request): JsonResponse
    {
        $organization = $this->organization($request);
        $email = $request->validated('email');
        $domain = $organization->domains()->where('domain', Str::after($email, '@'))->firstOrFail();

        $address = $organization->senderAddresses()->create([
            'domain_id' => $domain->id,
            'email' => $email,
            'name' => $request->validated('name'),
        ]);

        return response()->json(['data' => $this->present($address->load('domain'))], 201);
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
        ];
    }
}
