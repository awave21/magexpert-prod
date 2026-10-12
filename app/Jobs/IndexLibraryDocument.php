<?php

namespace App\Jobs;

use App\Models\MedicalLibrary;
use App\Services\Assistant\LibraryIndexer;
use App\Services\Assistant\PopplerChunker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Документ из админки сразу попадает в поиск ИИ-помощника: разбор poppler на сервере и векторы через шлюз.
 * Если этот же файл уже проиндексирован (например, качественным разбором Docling), ничего не делает.
 */
class IndexLibraryDocument implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $documentId)
    {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    public function handle(PopplerChunker $chunker, LibraryIndexer $indexer): void
    {
        $document = MedicalLibrary::query()->with('libraryIndex')->find($this->documentId);
        $hash = $document ? LibraryIndexer::fileHash($document) : null;

        if (! $document || ! $hash || $document->libraryIndex?->file_sha256 === $hash) {
            return;
        }

        $export = $chunker->chunk(Storage::disk('public')->path($document->file_path));
        $indexer->index($document, $export, 'poppler', self::popplerVersion());
    }

    private static function popplerVersion(): ?string
    {
        $result = Process::run(['pdftotext', '-v']);

        return preg_match('/version\s+([\d.]+)/i', $result->output().$result->errorOutput(), $match) ? $match[1] : null;
    }
}
