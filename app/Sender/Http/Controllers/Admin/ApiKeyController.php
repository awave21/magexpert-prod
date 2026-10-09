<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\StoreApiKeyRequest;
use App\Sender\Http\Resources\ApiKeyResource;
use App\Sender\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class ApiKeyController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        return ApiKeyResource::collection($this->organization($request)->apiKeys()->latest()->get());
    }

    public function store(StoreApiKeyRequest $request, ApiKeyService $keys): JsonResponse
    {
        $result = $keys->create($this->organization($request), $request->string('name')->toString());

        return response()->json([
            'data' => new ApiKeyResource($result['key']),
            'plain' => $result['plain'],
        ], 201);
    }

    public function destroy(Request $request, int $apiKey, ApiKeyService $keys): JsonResponse
    {
        $keys->revoke($this->organization($request)->apiKeys()->findOrFail($apiKey));

        return response()->json(['message' => 'Ключ отозван']);
    }
}
