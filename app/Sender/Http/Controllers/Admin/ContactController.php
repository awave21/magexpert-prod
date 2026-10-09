<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\ImportContactsRequest;
use App\Sender\Http\Requests\Admin\StoreContactRequest;
use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Services\ContactImportService;
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

        return response()->json(['data' => $this->present($contact)], $contact->wasRecentlyCreated ? 201 : 200);
    }

    public function import(ImportContactsRequest $request, int $list, ContactImportService $importer): JsonResponse
    {
        $content = $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : (string) $request->input('text');

        return response()->json(['data' => $importer->import($this->list($request, $list), $content)]);
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
            'created_at' => $contact->created_at?->toIso8601String(),
        ];
    }
}
