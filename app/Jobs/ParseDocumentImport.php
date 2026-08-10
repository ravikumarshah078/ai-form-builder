<?php

namespace App\Jobs;

use App\Models\DocumentImport;
use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Import\DocxParser;
use App\Services\Import\ImportException;
use App\Services\Import\SchemaRefiner;
use App\Services\Import\XlsxParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Parses an uploaded document, then optionally refines it with AI.
 *
 * Queued because the brief asks for it ("queue large files") and because a
 * 500-row spreadsheet plus an LLM call is far too long to hold a web request.
 *
 * The job ends at `awaiting_review`, never at `committed`. That pause is the
 * brief's "preview and mapping screen before committing", and making it a
 * status rather than a UI convention means a job can never create a form
 * behind the user's back.
 */
class ParseDocumentImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $importId,
    ) {}

    public function handle(LlmProvider $provider): void
    {
        $import = DocumentImport::find($this->importId);

        if ($import === null || $import->isFinished()) {
            return;
        }

        $import->update(['status' => 'parsing']);

        // The parser needs a real path; the file may live on a remote disk.
        $localPath = $this->materialise($import);

        try {
            // ── Stage 1: deterministic. No AI, fully reproducible.
            $result = $import->source === 'docx'
                ? (new DocxParser)->parse($localPath, pathinfo($import->original_filename, PATHINFO_FILENAME))
                : (new XlsxParser)->parse($localPath, pathinfo($import->original_filename, PATHINFO_FILENAME));
        } catch (ImportException $e) {
            // Message is written for the uploader, so it goes straight through.
            $import->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return;
        } catch (Throwable $e) {
            Log::error('Document import crashed', [
                'import' => $import->uuid,
                'exception' => $e->getMessage(),
            ]);

            $import->update([
                'status' => 'failed',
                'error' => 'That document could not be read. It may be corrupted or in an unexpected format.',
            ]);

            return;
        } finally {
            $this->cleanUp($import, $localPath);
        }

        // Keep the deterministic result untouched, always. The review screen
        // shows it beside the AI's suggestion, and "revert to what the parser
        // found" has to remain possible without re-uploading.
        $import->parsed_schema = $result->schema;

        // ── Stage 2: AI, but only for what stage 1 could not settle.
        $refined = (new SchemaRefiner($provider))->refine($result);

        $stats = $result->stats();
        $stats['ai_used'] = $refined['used'];
        $stats['ai_tokens'] = $refined['tokens'];
        $stats['ai_latency_ms'] = $refined['latency_ms'];
        $stats['ai_considered'] = count($result->uncertainFieldIds());
        $stats['detections'] = $refined['detections'];

        $import->update([
            'parsed_schema' => $result->schema,
            'proposed_schema' => $refined['schema'],
            'warnings' => $result->warnings,
            'stats' => $stats,
            // The pause. A form is only created when the user commits.
            'status' => 'awaiting_review',
        ]);
    }

    /**
     * Give the parser a path on the local filesystem.
     *
     * PhpWord and PhpSpreadsheet both need a real file, so a non-local disk is
     * streamed to a temporary path first.
     *
     * @return string  the path to read, and the one cleanUp() may delete
     */
    private function materialise(DocumentImport $import): string
    {
        $disk = Storage::disk($import->disk);

        if ($import->disk === 'local' || $import->disk === 'public') {
            return $disk->path($import->path);
        }

        $temp = tempnam(sys_get_temp_dir(), 'import_');
        file_put_contents($temp, $disk->get($import->path));

        return $temp;
    }

    private function cleanUp(DocumentImport $import, string $path): void
    {
        // Only remove what we created; never the stored upload itself.
        if ($import->disk !== 'local' && $import->disk !== 'public' && is_file($path)) {
            @unlink($path);
        }
    }

    public function failed(?Throwable $e): void
    {
        DocumentImport::where('id', $this->importId)
            ->whereIn('status', ['queued', 'parsing'])
            ->update([
                'status' => 'failed',
                'error' => $e?->getMessage() ?? 'The import job failed unexpectedly.',
            ]);
    }
}
