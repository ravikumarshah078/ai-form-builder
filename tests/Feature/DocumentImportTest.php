<?php

use App\Jobs\ParseDocumentImport;
use App\Livewire\Imports\Review;
use App\Livewire\Imports\Upload;
use App\Models\DocumentImport;
use App\Models\Form;
use App\Models\User;
use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\FakeProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Part C end to end: upload, queued parse, AI refinement, mapping screen, commit.
 */

beforeEach(function () {
    FakeProvider::reset();
    $this->provider = new FakeProvider;
    app()->instance(LlmProvider::class, $this->provider);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    if (! is_file(base_path('database/samples/internship-application.docx'))) {
        $this->markTestSkipped('Run `php artisan import:samples` first.');
    }
});

/**
 * Copy a committed sample into storage and return its import row.
 */
function importOf(string $file): DocumentImport
{
    $source = base_path('database/samples/'.$file);
    $extension = pathinfo($file, PATHINFO_EXTENSION);
    $path = 'imports/'.test()->user->id.'/'.$file;

    Storage::disk('local')->put($path, file_get_contents($source));

    return DocumentImport::create([
        'user_id' => test()->user->id,
        'source' => $extension,
        'original_filename' => $file,
        'disk' => 'local',
        'path' => $path,
        'size' => filesize($source),
        'status' => 'queued',
    ]);
}

// ── Upload ───────────────────────────────────────────────────────────────

it('stores an upload and queues the parse', function () {
    Storage::fake('local');
    Illuminate\Support\Facades\Queue::fake();

    Livewire::test(Upload::class)
        ->set('document', UploadedFile::fake()->create('form.docx', 20,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
        ->call('save')
        ->assertHasNoErrors();

    $import = DocumentImport::latest('id')->first();

    expect($import->status)->toBe('queued')->and($import->source)->toBe('docx');

    Illuminate\Support\Facades\Queue::assertPushed(ParseDocumentImport::class);
});

it('rejects a file type it does not support', function () {
    Storage::fake('local');

    Livewire::test(Upload::class)
        ->set('document', UploadedFile::fake()->create('payload.exe', 10))
        ->call('save')
        ->assertHasErrors('document');

    expect(DocumentImport::count())->toBe(0);
});

it('rejects a file above the size limit', function () {
    Storage::fake('local');

    Livewire::test(Upload::class)
        ->set('document', UploadedFile::fake()->create('huge.xlsx', 20000,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))
        ->call('save')
        ->assertHasErrors('document');
});

// ── The job ──────────────────────────────────────────────────────────────

it('parses a Word document and pauses for review', function () {
    $import = importOf('internship-application.docx');

    (new ParseDocumentImport($import->id))->handle($this->provider);

    $import->refresh();

    // The pause is a status, not a UI convention, so a job can never create a
    // form behind the user's back.
    expect($import->status)->toBe('awaiting_review')
        ->and($import->parsed_schema)->not->toBeNull()
        ->and($import->proposed_schema)->not->toBeNull()
        ->and($import->stats['fields'])->toBe(12)
        ->and(Form::count())->toBe(0);
});

it('keeps the deterministic parse alongside the refined one', function () {
    $import = importOf('internship-application.docx');

    (new ParseDocumentImport($import->id))->handle($this->provider);

    $import->refresh();

    // Both are kept so "revert to what the parser found" needs no re-upload.
    expect($import->parsed_schema)->toBeValidSchema()
        ->and($import->proposed_schema)->toBeValidSchema();
});

it('sends only the uncertain fields to the AI', function () {
    $import = importOf('internship-application.docx');

    (new ParseDocumentImport($import->id))->handle($this->provider);

    $import->refresh();

    $sent = $import->stats['ai_considered'];

    // One ambiguous field out of twelve. The AI never sees the whole document.
    expect($sent)->toBeGreaterThan(0)
        ->and($sent)->toBeLessThan($import->stats['fields']);

    $prompt = FakeProvider::calls()[0]['user'] ?? '';

    expect($prompt)->toContain('Which institution did you attend?')
        // A field the parser was sure about is not in the prompt at all.
        ->and($prompt)->not->toContain('What is your email address?');
});

it('skips the AI entirely when nothing is ambiguous', function () {
    $import = importOf('field-definitions.xlsx');

    (new ParseDocumentImport($import->id))->handle($this->provider);

    $import->refresh();

    // Every type is declared in the sheet, so the import costs nothing.
    expect($import->stats['ai_considered'])->toBe(0)
        ->and($import->stats['ai_used'])->toBeFalse()
        ->and(FakeProvider::callCount())->toBe(0);
});

it('falls back to the deterministic parse when the AI fails', function () {
    FakeProvider::queue(App\Services\Ai\LlmException::api('server error', 500));

    $import = importOf('internship-application.docx');

    (new ParseDocumentImport($import->id))->handle($this->provider);

    $import->refresh();

    // Degrade, never fail: the parser's result is still perfectly usable.
    expect($import->status)->toBe('awaiting_review')
        ->and($import->proposed_schema)->toBeValidSchema();
});

it('records a readable error for an unparseable document', function () {
    Storage::disk('local')->put('imports/broken.docx', 'not a word document');

    $import = DocumentImport::create([
        'user_id' => $this->user->id,
        'source' => 'docx',
        'original_filename' => 'broken.docx',
        'disk' => 'local',
        'path' => 'imports/broken.docx',
        'size' => 20,
        'status' => 'queued',
    ]);

    (new ParseDocumentImport($import->id))->handle($this->provider);

    $import->refresh();

    expect($import->status)->toBe('failed')
        ->and($import->error)->toContain('Word document')
        ->and(Form::count())->toBe(0);
});

// ── The mapping screen ───────────────────────────────────────────────────

it('shows the review screen with every detected field', function () {
    $import = importOf('internship-application.docx');
    (new ParseDocumentImport($import->id))->handle($this->provider);

    Livewire::test(Review::class, ['import' => $import->fresh()])
        ->assertOk()
        ->assertSee('What is your email address?')
        ->assertSee('Highest qualification');
});

it('lets the user correct a wrongly detected type', function () {
    $import = importOf('internship-application.docx');
    (new ParseDocumentImport($import->id))->handle($this->provider);

    $component = Livewire::test(Review::class, ['import' => $import->fresh()]);

    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];

    $component->call('setType', $fieldId, 'textarea');

    expect($component->get('schema')['sections'][0]['fields'][0]['type'])->toBe('textarea');
});

it('invents options when a field is switched to a choice type', function () {
    $import = importOf('internship-application.docx');
    (new ParseDocumentImport($import->id))->handle($this->provider);

    $component = Livewire::test(Review::class, ['import' => $import->fresh()]);
    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];

    $component->call('setType', $fieldId, 'dropdown');

    // Routed through the normaliser, so a choice field is never left unusable.
    expect($component->get('schema')['sections'][0]['fields'][0]['options'])->not->toBeEmpty();
});

it('lets the user drop a field', function () {
    $import = importOf('internship-application.docx');
    (new ParseDocumentImport($import->id))->handle($this->provider);

    $component = Livewire::test(Review::class, ['import' => $import->fresh()]);
    $before = count($component->get('schema')['sections'][0]['fields']);
    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];

    $component->call('removeField', $fieldId);

    expect($component->get('schema')['sections'][0]['fields'])->toHaveCount($before - 1);
});

it('can revert to the raw parse', function () {
    $import = importOf('internship-application.docx');
    (new ParseDocumentImport($import->id))->handle($this->provider);
    $import->refresh();

    $component = Livewire::test(Review::class, ['import' => $import]);
    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];

    $component->call('setType', $fieldId, 'textarea')->call('revertToParsed');

    expect($component->get('schema'))->toBe($import->parsed_schema);
});

// ── Commit ───────────────────────────────────────────────────────────────

it('creates a draft form on commit', function () {
    $import = importOf('internship-application.docx');
    (new ParseDocumentImport($import->id))->handle($this->provider);

    Livewire::test(Review::class, ['import' => $import->fresh()])->call('commit');

    $form = Form::latest('id')->first();

    expect($form)->not->toBeNull()
        // Never auto-published: a document should not become a live public URL
        // without anyone looking at it.
        ->and($form->status->value)->toBe('draft')
        ->and($form->currentVersion->origin)->toBe('import')
        ->and($form->currentVersion->schema)->toBeValidSchema()
        ->and($import->fresh()->status)->toBe('committed')
        ->and($import->fresh()->form_id)->toBe($form->id);
});

it('carries the user\'s corrections into the created form', function () {
    $import = importOf('internship-application.docx');
    (new ParseDocumentImport($import->id))->handle($this->provider);

    $component = Livewire::test(Review::class, ['import' => $import->fresh()]);
    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];

    $component->call('setType', $fieldId, 'textarea')
        ->set('title', 'Corrected title')
        ->call('commit');

    $form = Form::latest('id')->first();

    expect($form->title)->toBe('Corrected title')
        ->and($form->currentVersion->schema['sections'][0]['fields'][0]['type'])->toBe('textarea');
});

it('refuses to open an import belonging to someone else', function () {
    $import = importOf('internship-application.docx');
    (new ParseDocumentImport($import->id))->handle($this->provider);

    $this->actingAs(User::factory()->create());

    Livewire::test(Review::class, ['import' => $import->fresh()])->assertForbidden();
});
