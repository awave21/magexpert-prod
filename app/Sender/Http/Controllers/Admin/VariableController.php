<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\VariableRequest;
use App\Sender\Models\Variable;
use App\Sender\Services\BaseVariables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VariableController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): JsonResponse
    {
        $custom = $this->organization($request)->variables()->orderBy('key')->get()->map(fn (Variable $v): array => $this->present($v));

        return response()->json(['base' => BaseVariables::all(), 'custom' => $custom]);
    }

    public function store(VariableRequest $request): JsonResponse
    {
        $variable = $this->organization($request)->variables()->create($request->validated());

        return response()->json(['data' => $this->present($variable)], 201);
    }

    public function update(VariableRequest $request, int $variable): JsonResponse
    {
        $model = $this->organization($request)->variables()->findOrFail($variable);
        $model->update($request->validated());

        return response()->json(['data' => $this->present($model)]);
    }

    public function destroy(Request $request, int $variable): JsonResponse
    {
        $this->organization($request)->variables()->findOrFail($variable)->delete();

        return response()->json(['message' => 'Переменная удалена']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Variable $variable): array
    {
        return [
            'id' => $variable->id,
            'key' => $variable->key,
            'label' => $variable->label,
            'default_value' => $variable->default_value,
        ];
    }
}
