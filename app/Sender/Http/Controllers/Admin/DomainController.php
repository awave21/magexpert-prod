<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\StoreDomainRequest;
use App\Sender\Http\Resources\DomainResource;
use App\Sender\Services\DnsAdvisor;
use App\Sender\Services\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class DomainController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        return DomainResource::collection($this->organization($request)->domains()->latest()->get());
    }

    public function store(StoreDomainRequest $request, DomainService $domains): JsonResponse
    {
        $domain = $domains->add($this->organization($request), $request->string('domain')->toString());

        return (new DomainResource($domain))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $domain): DomainResource
    {
        return new DomainResource($this->organization($request)->domains()->findOrFail($domain));
    }

    /**
     * Что уже есть в DNS домена и что с каждой записью сделать: подсказки для страницы настройки.
     */
    public function dnsCheck(Request $request, int $domain, DnsAdvisor $advisor): JsonResponse
    {
        return response()->json(['data' => $advisor->advise($this->organization($request)->domains()->findOrFail($domain))]);
    }

    public function verify(Request $request, int $domain, DomainService $domains): JsonResponse
    {
        $model = $this->organization($request)->domains()->findOrFail($domain);

        $report = $domains->verify($model);

        return response()->json([
            'data' => (new DomainResource($model->fresh()))->toArray($request),
            'report' => $report,
        ]);
    }

    public function destroy(Request $request, int $domain): JsonResponse
    {
        $this->organization($request)->domains()->findOrFail($domain)->delete();

        return response()->json(['message' => 'Домен удалён']);
    }
}
