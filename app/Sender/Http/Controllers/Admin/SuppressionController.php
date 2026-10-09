<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\StoreSuppressionRequest;
use App\Sender\Http\Resources\SuppressionResource;
use App\Sender\Models\Suppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class SuppressionController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->organization($request)->suppressions()->latest('id');

        if ($request->filled('q')) {
            $query->where('email', 'like', '%'.$request->string('q')->lower().'%');
        }

        return SuppressionResource::collection($query->paginate(25)->withQueryString());
    }

    public function store(StoreSuppressionRequest $request): JsonResponse
    {
        $suppression = $this->organization($request)->suppressions()->updateOrCreate(
            ['email' => $request->string('email')->lower()->toString()],
            ['reason' => Suppression::REASON_MANUAL],
        );

        return (new SuppressionResource($suppression))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, int $suppression): JsonResponse
    {
        $this->organization($request)->suppressions()->findOrFail($suppression)->delete();

        return response()->json(['message' => 'Адрес удалён из списка блокировок']);
    }
}
