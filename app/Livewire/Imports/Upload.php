<?php

namespace App\Livewire\Imports;

use App\Jobs\ParseDocumentImport;
use App\Models\DocumentImport;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Upload a .docx or .xlsx and start the parse.
 *
 * Nothing is parsed inline. The upload is stored, a job is dispatched and the
 * browser polls, exactly as AI generation does — a 500-row spreadsheet plus an
 * LLM call is far too long to hold a request open.
 */
#[Layout('layouts.app')]
class Upload extends Component
{
    use WithFileUploads;

    /**
     * Extension AND size are both enforced. `mimes` checks real file content,
     * not the name, so renaming payload.exe to payload.docx does not get past
     * this.
     */
    #[Validate('required|file|mimes:docx,xlsx|max:10240')]
    public $document;

    public ?string $importUuid = null;

    public function mount(): void
    {
        // Resume an import that was still parsing when the page reloaded.
        $running = DocumentImport::where('user_id', auth()->id())
            ->whereIn('status', ['queued', 'parsing'])
            ->latest('id')
            ->first();

        if ($running !== null) {
            $this->importUuid = $running->uuid;
        }
    }

    public function import(): ?DocumentImport
    {
        if ($this->importUuid === null) {
            return null;
        }

        return DocumentImport::where('uuid', $this->importUuid)
            ->where('user_id', auth()->id())
            ->first();
    }

    public function save(): void
    {
        $this->validate();

        $extension = strtolower($this->document->getClientOriginalExtension());

        $import = DocumentImport::create([
            'user_id' => auth()->id(),
            'source' => $extension === 'docx' ? 'docx' : 'xlsx',
            'original_filename' => $this->document->getClientOriginalName(),
            'disk' => 'local',
            // Stored under the user's own directory with a generated name.
            'path' => $this->document->store('imports/'.auth()->id(), 'local'),
            'size' => $this->document->getSize(),
            'status' => 'queued',
        ]);

        $this->importUuid = $import->uuid;
        $this->document = null;

        ParseDocumentImport::dispatch($import->id);
    }

    /**
     * Polled while parsing. Moves to the review screen once it is ready.
     */
    public function poll()
    {
        $import = $this->import();

        if ($import === null) {
            return null;
        }

        if ($import->awaitingReview()) {
            return $this->redirectRoute('imports.review', ['import' => $import], navigate: true);
        }

        return null;
    }

    public function dismiss(): void
    {
        $this->importUuid = null;
    }

    public function render()
    {
        return view('livewire.imports.upload', [
            'import' => $this->import(),
            'recent' => DocumentImport::where('user_id', auth()->id())
                ->latest('id')->limit(5)->get(),
        ]);
    }
}
