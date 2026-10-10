<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\ImportContactsRequest;
use App\Sender\Http\Requests\Admin\StoreContactRequest;
use App\Sender\Jobs\CheckContactsJob;
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
            $query->whereNull('unsubscribed_at');
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
        $content = $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : (string) $request->input('text');

        $model = $this->list($request, $list);
        $result = $importer->import($model, $content);
        CheckContactsJob::dispatch($model->id)->onQueue(config('sender.queue'));

        return response()->json(['data' => $result]);
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
            'check_status' => $contact->check_status,
            'check_hint' => $contact->check_hint,
            'created_at' => $contact->created_at?->toIso8601String(),
        ];
    }
}
