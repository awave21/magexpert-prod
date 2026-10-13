<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\MoveTemplateRequest;
use App\Sender\Http\Requests\Admin\TemplateRequest;
use App\Sender\Http\Requests\Admin\TestTemplateRequest;
use App\Sender\Http\Resources\MessageResource;
use App\Sender\Http\Resources\TemplateResource;
use App\Sender\Models\Domain;
use App\Sender\Services\MessageService;
use App\Sender\Services\TemplateRenderer;
use App\Sender\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class TemplateController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        return TemplateResource::collection($this->organization($request)->templates()->orderBy('name')->get());
    }

    public function store(TemplateRequest $request, HtmlSanitizer $sanitizer): JsonResponse
    {
        $data = $this->sanitized($request, $sanitizer);
        $slugGiven = filled($data['slug'] ?? null);

        // алиас для API необязателен: пока его не задали, письмо доступно по draft-<ID>
        $data['slug'] = $slugGiven ? $data['slug'] : 'draft-'.Str::lower(Str::random(12));
        $organization = $this->organization($request);
        $template = $organization->templates()->create($data);

        if (! $slugGiven) {
            $draft = 'draft-'.$template->id;

            if (! $organization->templates()->where('slug', $draft)->exists()) {
                $template->update(['slug' => $draft]);
            }
        }

        return (new TemplateResource($template))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $template): TemplateResource
    {
        return new TemplateResource($this->organization($request)->templates()->findOrFail($template));
    }

    public function update(TemplateRequest $request, int $template, HtmlSanitizer $sanitizer): TemplateResource
    {
        $model = $this->organization($request)->templates()->findOrFail($template);
        $data = $this->sanitized($request, $sanitizer);

        // пустой алиас не затирает прежний
        if (blank($data['slug'] ?? null)) {
            unset($data['slug']);
        }

        $model->update($data);

        return new TemplateResource($model);
    }

    /**
     * Данные формы без исполняемого HTML: письмо и блоки визуального редактора открываются в админке.
     *
     * @return array<string, mixed>
     */
    private function sanitized(TemplateRequest $request, HtmlSanitizer $sanitizer): array
    {
        $data = $request->validated();
        $data['body_html'] = $sanitizer->clean((string) $data['body_html']);

        if (isset($data['design']) && is_array($data['design'])) {
            $data['design'] = $sanitizer->design($data['design']);
        }

        return $data;
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

    /**
     * Тестовая отправка сохранённой версии шаблона с первого подтверждённого домена организации.
     * Письмо проходит обычный путь: очередь, журнал, список блокировок.
     */
    public function test(TestTemplateRequest $request, int $template, MessageService $messages): JsonResponse
    {
        $organization = $this->organization($request);
        $model = $organization->templates()->findOrFail($template);
        $domain = $organization->domains()->where('status', Domain::STATUS_VERIFIED)->oldest('id')->first();

        if ($domain === null) {
            return response()->json(['message' => 'Нет подтверждённого домена: тестовое письмо отправить не с чего'], 422);
        }

        $message = $messages->send(
            $organization,
            $model,
            $request->string('to')->toString(),
            'noreply@'.$domain->domain,
            $organization->name,
            (array) $request->validated('data', []),
        );

        return (new MessageResource($message->load('template')))->response()->setStatusCode(201);
    }
}
