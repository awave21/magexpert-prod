<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\MoveTemplateRequest;
use App\Sender\Http\Requests\Admin\TemplateRequest;
use App\Sender\Http\Resources\TemplateResource;
use App\Sender\Services\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class TemplateController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        return TemplateResource::collection($this->organization($request)->templates()->orderBy('name')->get());
    }

    public function store(TemplateRequest $request): JsonResponse
    {
        $template = $this->organization($request)->templates()->create($request->validated());

        return (new TemplateResource($template))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $template): TemplateResource
    {
        return new TemplateResource($this->organization($request)->templates()->findOrFail($template));
    }

    public function update(TemplateRequest $request, int $template): TemplateResource
    {
        $model = $this->organization($request)->templates()->findOrFail($template);
        $model->update($request->validated());

        return new TemplateResource($model);
    }

    /**
     * Переносит шаблон в другую папку; folder_id = null убирает его из папки.
     */
    public function move(MoveTemplateRequest $request, int $template): TemplateResource
    {
        $model = $this->organization($request)->templates()->findOrFail($template);
        $model->update(['folder_id' => $request->validated('folder_id')]);

        return new TemplateResource($model);
    }

    public function destroy(Request $request, int $template): JsonResponse
    {
        $this->organization($request)->templates()->findOrFail($template)->delete();

        return response()->json(['message' => 'Шаблон удалён']);
    }

    public function preview(Request $request, int $template, TemplateRenderer $renderer): JsonResponse
    {
        $model = $this->organization($request)->templates()->findOrFail($template);

        return response()->json(['data' => $renderer->render($model, array_merge($this->organization($request)->variables()->whereNotNull('default_value')->where('default_value', '!=', '')->pluck('default_value', 'key')->all(), (array) $request->input('data', [])))]);
    }
}
