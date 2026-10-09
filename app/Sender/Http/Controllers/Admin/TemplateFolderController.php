<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\TemplateFolderRequest;
use App\Sender\Models\TemplateFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TemplateFolderController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): JsonResponse
    {
        $folders = $this->organization($request)->templateFolders()->withCount('templates')->orderBy('name')->get();

        return response()->json(['data' => $folders->map(fn (TemplateFolder $f): array => $this->present($f))]);
    }

    public function store(TemplateFolderRequest $request): JsonResponse
    {
        $folder = $this->organization($request)->templateFolders()->create($request->validated());

        return response()->json(['data' => $this->present($folder)], 201);
    }

    public function update(TemplateFolderRequest $request, int $folder): JsonResponse
    {
        $model = $this->organization($request)->templateFolders()->findOrFail($folder);
        $model->update($request->validated());

        return response()->json(['data' => $this->present($model)]);
    }

    /**
     * Шаблоны из удалённой папки не удаляются, а остаются без папки.
     */
    public function destroy(Request $request, int $folder): JsonResponse
    {
        $this->organization($request)->templateFolders()->findOrFail($folder)->delete();

        return response()->json(['message' => 'Папка удалена']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TemplateFolder $folder): array
    {
        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'templates_count' => (int) ($folder->templates_count ?? 0),
        ];
    }
}
