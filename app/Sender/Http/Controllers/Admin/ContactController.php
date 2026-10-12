<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\ImportContactsRequest;
use App\Sender\Http\Requests\Admin\StoreContactRequest;
use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Jobs\SendSubscriptionConfirmationsJob;
use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Services\ContactImportService;
use App\Sender\Services\EmailChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ContactController extends Controller
{
    use ResolvesOrganization;

    private const SYNCED = 'База обновляется из источника автоматически: добавлять адреса вручную нельзя';

    public function index(Request $request, int $list): JsonResponse
    {
        $query = $this->list($request, $list)->contacts()->latest('id');

        if ($request->filled('q')) {
            $raw = trim((string) $request->string('q'));
            $variants = array_unique([$raw, mb_strtolower($raw), mb_convert_case($raw, MB_CASE_TITLE)]);
            $query->where(function ($w) use ($raw, $variants): void {
                $w->where('email', 'like', '%'.mb_strtolower($raw).'%');
                foreach ($variants as $variant) {
                    $w->orWhere('name', 'like', '%'.$variant.'%');
                }
            });
        }

        if ($request->input('status') === 'unsubscribed') {
            $query->whereNotNull('unsubscribed_at');
        } elseif ($request->input('status') === 'subscribed') {
            $query->subscribed();
        } elseif ($request->input('status') === 'pending') {
            $query->whereNull('unsubscribed_at')->pending();
        }

        if ($request->input('check') === 'unchecked') {
            $query->whereNull('checked_at');
        } elseif ($request->filled('check')) {
            $query->where('check_status', (string) $request->input('check'));
        }

        $page = $query->paginate(50)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Contact $c): array => $this->present($c)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(StoreContactRequest $request, int $list): JsonResponse
    {
        $model = $this->list($request, $list);
        abort_if($model->isSynced(), 422, self::SYNCED);
        $contact = $model->contacts()->updateOrCreate(
            ['email' => $request->validated('email')],
            ['organization_id' => $model->organization_id, 'name' => $request->validated('name')],
        );
        $model->touch();
        app(EmailChecker::class)->checkContacts([$contact]);

        return response()->json(['data' => $this->present($contact)], $contact->wasRecentlyCreated ? 201 : 200);
    }

    public function import(ImportContactsRequest $request, int $list, ContactImportService $importer): JsonResponse
    {
        $model = $this->list($request, $list);
        abort_if($model->isSynced(), 422, self::SYNCED);
        $result = $importer->import($model, $this->content($request), $request->mapping(), $request->consent(! $model->require_confirmation));
        CheckContactsJob::dispatch($model->id)->onQueue(config('sender.queue'));

        // письма подтверждения уходят чуть позже: проверка успеет отсеять несуществующие адреса
        if ($result['pending'] > 0) {
            SendSubscriptionConfirmationsJob::dispatch($model->id)->onQueue(config('sender.queue'))->delay(now()->addMinutes(2));
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Колонки файла до загрузки, чтобы выбрать, где адрес, где имя и что сохранить переменными.
     */
    public function preview(ImportContactsRequest $request, ContactImportService $importer): JsonResponse
    {
        return response()->json(['data' => $importer->preview($this->content($request))]);
    }

    private function content(ImportContactsRequest $request): string
    {
        return $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : (string) $request->input('text');
    }

    /**
     * Исправляет опечатку в домене по подсказке проверки: gmial.com → gmail.com.
     */
    public function fix(Request $request, int $list, int $contact, EmailChecker $checker): JsonResponse
    {
        $model = $this->list($request, $list)->contacts()->findOrFail($contact);
        abort_unless($model->check_status === EmailChecker::STATUS_TYPO && $model->check_hint, 422, 'Для этого адреса нет исправления');

        $this->applyHint($model, $checker);

        return response()->json(['message' => 'Адрес исправлен']);
    }

    public function fixAll(Request $request, int $list, EmailChecker $checker): JsonResponse
    {
        $fixed = 0;
        $this->list($request, $list)->contacts()->where('check_status', EmailChecker::STATUS_TYPO)->whereNotNull('check_hint')
            ->chunkById(500, function ($contacts) use ($checker, &$fixed): void {
                foreach ($contacts as $contact) {
                    $this->applyHint($contact, $checker);
                    $fixed++;
                }
            });

        return response()->json(['data' => ['fixed' => $fixed]]);
    }

    /**
     * Если исправленный адрес уже есть в базе, опечатка просто удаляется.
     */
    private function applyHint(Contact $contact, EmailChecker $checker): void
    {
        $email = (string) $contact->check_hint;

        if (Contact::query()->where('list_id', $contact->list_id)->where('email', $email)->exists()) {
            $contact->delete();

            return;
        }

        $contact->forceFill(['email' => $email, 'check_status' => null, 'check_hint' => null, 'checked_at' => null])->save();
        $checker->checkContacts([$contact]);
    }

    public function destroy(Request $request, int $list, int $contact): JsonResponse
    {
        $this->list($request, $list)->contacts()->findOrFail($contact)->delete();

        return response()->json(['message' => 'Подписчик удалён']);
    }

    private function list(Request $request, int $list): ContactList
    {
        return $this->organization($request)->lists()->findOrFail($list);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'email' => $contact->email,
            'name' => $contact->name,
            'data' => $contact->data ?? (object) [],
            'unsubscribed_at' => $contact->unsubscribed_at?->toIso8601String(),
            'pending' => $contact->isPending(),
            'confirmation_sent_at' => $contact->confirmation_sent_at?->toIso8601String(),
            'confirmed_at' => $contact->confirmed_at?->toIso8601String(),
            'check_status' => $contact->check_status,
            'check_hint' => $contact->check_hint,
            'last_opened_at' => $contact->last_opened_at?->toIso8601String(),
            'created_at' => $contact->created_at?->toIso8601String(),
        ];
    }
}
