<?php

namespace App\Livewire\Forms;

use App\Enums\FieldType;
use App\Forms\FieldFactory;
use App\Forms\FormSchema;
use App\Forms\SchemaNormaliser;
use App\Forms\SchemaValidator;
use App\Jobs\RunAiGeneration;
use App\Models\AiGeneration;
use App\Models\Form;
use App\Models\FormVersion;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step 2 of the wizard: the drag-and-drop canvas.
 *
 * The component holds ONE piece of authoritative state: `$schema`, a plain
 * array matching the schema contract. The canvas, the field options panel and
 * the raw JSON editor are three views onto that same array, which is what
 * makes the brief's "two-way sync" requirement fall out for free rather than
 * needing a synchronisation mechanism.
 *
 * Nothing here writes to the database until save(). Editing is a pure
 * in-memory transformation of the array, so undo/redo and autosave (Part D)
 * only need to snapshot it.
 */
#[Layout('layouts.app')]
class Builder extends Component
{
    public Form $form;

    /**
     * The working copy. Every action mutates this and nothing else.
     *
     * @var array<string, mixed>
     */
    public array $schema = [];

    /** Which right-hand panel is showing: add | options | json */
    public string $tab = 'add';

    public ?string $selectedFieldId = null;

    /** The raw JSON editor's textarea contents. */
    public string $rawJson = '';

    /**
     * Validation errors from the last JSON parse, keyed by schema path.
     *
     * @var array<string, array<int, string>>
     */
    public array $jsonErrors = [];

    /** True once the schema differs from the saved version. */
    public bool $dirty = false;

    /** The AI edit instruction box. */
    public string $aiInstruction = '';

    /** Set while an AI edit is running; the view polls on it. */
    public ?string $aiGenerationUuid = null;

    public function mount(Form $form): void
    {
        abort_unless($form->user_id === auth()->id(), 403);

        $this->form = $form;

        // A form always has a v1, so this is never null in practice. The
        // fallback covers a row hand-edited in the database.
        $this->schema = $form->currentVersion?->schema
            ?? FieldFactory::emptySchema($form->title, $form->description);

        $this->syncJsonFromSchema();
    }

    // ── Derived state ────────────────────────────────────────────────────

    /**
     * The palette, grouped the way the reference UI groups it.
     *
     * @return array<string, array<int, FieldType>>
     */
    #[Computed]
    public function palette(): array
    {
        $groups = [];

        foreach (FieldType::cases() as $type) {
            $groups[$type->group()][] = $type;
        }

        return $groups;
    }

    /**
     * Locate the selected field as [sectionIndex, fieldIndex].
     *
     * Returned rather than stored, because indices shift on every reorder and
     * a stale stored index would silently edit the wrong field.
     *
     * @return array{0: int, 1: int}|null
     */
    #[Computed]
    public function selectedPath(): ?array
    {
        if ($this->selectedFieldId === null) {
            return null;
        }

        foreach ($this->schema['sections'] ?? [] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                if (($field['id'] ?? null) === $this->selectedFieldId) {
                    return [$si, $fi];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function selectedField(): ?array
    {
        $path = $this->selectedPath();

        return $path === null ? null : $this->schema['sections'][$path[0]]['fields'][$path[1]];
    }

    #[Computed]
    public function selectedType(): ?FieldType
    {
        return FieldType::tryFrom($this->selectedField()['type'] ?? '');
    }

    /**
     * Live validation state, shown in the header as a badge.
     */
    #[Computed]
    public function validation()
    {
        return (new SchemaValidator)->validate($this->schema);
    }

    #[Computed]
    public function fieldCount(): int
    {
        return FormSchema::wrap($this->schema)->fieldCount();
    }

    // ── Sections ─────────────────────────────────────────────────────────

    public function addSection(): void
    {
        $this->schema['sections'][] = FieldFactory::makeSection([
            'title' => 'Section '.(count($this->schema['sections'] ?? []) + 1),
        ]);

        $this->touch();
    }

    public function deleteSection(int $index): void
    {
        if (! isset($this->schema['sections'][$index])) {
            return;
        }

        // Deselect first: the selected field may live in the section we are
        // about to remove.
        $this->selectedFieldId = null;

        array_splice($this->schema['sections'], $index, 1);

        $this->touch();
    }

    public function moveSection(int $from, int $to): void
    {
        $sections = $this->schema['sections'] ?? [];

        if (! isset($sections[$from]) || $to < 0 || $to >= count($sections)) {
            return;
        }

        $moved = array_splice($sections, $from, 1);
        array_splice($sections, $to, 0, $moved);

        $this->schema['sections'] = $sections;

        $this->touch();
    }

    // ── Fields ───────────────────────────────────────────────────────────

    /**
     * Click-to-add from the palette. The brief requires this alongside drag
     * and drop, so both routes end here.
     */
    public function addField(string $type, ?int $sectionIndex = null): void
    {
        $fieldType = FieldType::tryFrom($type);

        if ($fieldType === null) {
            return;
        }

        // A form with no sections yet gets one implicitly, so the first click
        // on the palette does something useful instead of erroring.
        if (empty($this->schema['sections'])) {
            $this->schema['sections'][] = FieldFactory::makeSection(['title' => null]);
        }

        $sectionIndex ??= count($this->schema['sections']) - 1;
        $sectionIndex = max(0, min($sectionIndex, count($this->schema['sections']) - 1));

        $field = FieldFactory::make($fieldType);

        // Keys must be unique across the whole form, not just this section.
        if ($fieldType->collectsAnswer()) {
            $field['key'] = FieldFactory::uniqueKey($field['label'], $this->allKeys());
        }

        $this->schema['sections'][$sectionIndex]['fields'][] = $field;

        // Select it and swing the panel over, so the user lands where they can
        // immediately configure what they just added.
        $this->selectedFieldId = $field['id'];
        $this->tab = 'options';

        $this->touch();
    }

    public function selectField(string $fieldId): void
    {
        $this->selectedFieldId = $fieldId;
        $this->tab = 'options';
    }

    public function duplicateField(string $fieldId): void
    {
        $path = $this->pathOf($fieldId);

        if ($path === null) {
            return;
        }

        [$si, $fi] = $path;

        $copy = $this->schema['sections'][$si]['fields'][$fi];

        // A duplicate needs its own identity, or the canvas would show two
        // elements Livewire cannot tell apart and the submission JSON would
        // collide on the key.
        $copy['id'] = FieldFactory::fieldId();

        if (! empty($copy['key'])) {
            $copy['key'] = FieldFactory::uniqueKey($copy['key'], $this->allKeys());
        }

        array_splice($this->schema['sections'][$si]['fields'], $fi + 1, 0, [$copy]);

        $this->selectedFieldId = $copy['id'];

        $this->touch();
    }

    public function deleteField(string $fieldId): void
    {
        $path = $this->pathOf($fieldId);

        if ($path === null) {
            return;
        }

        [$si, $fi] = $path;

        array_splice($this->schema['sections'][$si]['fields'], $fi, 1);

        if ($this->selectedFieldId === $fieldId) {
            $this->selectedFieldId = null;
            $this->tab = 'add';
        }

        $this->touch();
    }

    /**
     * Called by SortableJS after a drag.
     *
     * The browser's DOM change is reverted client-side before this fires, so
     * the server's array is the single authority on order and Livewire's
     * re-render is what actually moves the element.
     */
    public function moveField(string $fieldId, int $toSection, int $toIndex): void
    {
        $path = $this->pathOf($fieldId);

        if ($path === null || ! isset($this->schema['sections'][$toSection])) {
            return;
        }

        [$si, $fi] = $path;

        $moved = array_splice($this->schema['sections'][$si]['fields'], $fi, 1);

        if ($moved === []) {
            return;
        }

        $target = &$this->schema['sections'][$toSection]['fields'];
        $toIndex = max(0, min($toIndex, count($target)));

        array_splice($target, $toIndex, 0, $moved);

        $this->touch();
    }

    // ── Field options panel ──────────────────────────────────────────────

    /**
     * Regenerate the key when the label changes, but only while the key is
     * still the machine-derived one.
     *
     * Once a form is published its keys are load-bearing: they are the column
     * headers in every CSV already exported and the object keys in every
     * submission already stored. Renaming a label must not silently break that.
     */
    public function updatedSchema($value, string $path): void
    {
        $this->touch();

        if (! str_ends_with($path, '.label')) {
            return;
        }

        $fieldPath = substr($path, 0, -strlen('.label'));
        $keyPath = $fieldPath.'.key';

        $current = data_get($this->schema, $keyPath);

        // Never touch the key of a form that has already collected responses.
        if ($this->form->submission_count > 0) {
            return;
        }

        $previousLabel = data_get($this->schema, $fieldPath.'._previous_label');

        if ($current === null || $current === '' || $current === FieldFactory::keyFrom($previousLabel ?? '')) {
            $taken = array_diff($this->allKeys(), [$current]);
            data_set($this->schema, $keyPath, FieldFactory::uniqueKey($value, $taken));
        }

        data_set($this->schema, $fieldPath.'._previous_label', $value);
    }

    public function addOption(): void
    {
        $path = $this->selectedPath();

        if ($path === null) {
            return;
        }

        [$si, $fi] = $path;

        $options = &$this->schema['sections'][$si]['fields'][$fi]['options'];
        $n = count($options) + 1;

        $options[] = ['value' => 'option_'.$n, 'label' => 'Option '.$n];

        $this->touch();
    }

    public function removeOption(int $index): void
    {
        $path = $this->selectedPath();

        if ($path === null) {
            return;
        }

        [$si, $fi] = $path;

        array_splice($this->schema['sections'][$si]['fields'][$fi]['options'], $index, 1);

        $this->touch();
    }

    // ── Raw JSON editor: the two-way sync ────────────────────────────────

    public function setTab(string $tab): void
    {
        // Entering the JSON tab re-serialises from the canvas, so the editor
        // always opens showing current truth.
        if ($tab === 'json') {
            $this->syncJsonFromSchema();
        }

        $this->tab = $tab;
    }

    /**
     * JSON editor → canvas.
     *
     * Parse, normalise, validate. The canvas is only updated if the result is
     * valid, so a half-typed document cannot blank the user's work. Errors are
     * reported against schema paths so the user can find them.
     */
    public function applyJson(): void
    {
        $this->jsonErrors = [];

        $decoded = json_decode($this->rawJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->jsonErrors = ['' => ['Invalid JSON: '.json_last_error_msg()]];

            return;
        }

        // Same normaliser the AI and the importer use — a human pasting JSON
        // gets exactly the same forgiveness as a model producing it.
        $normalised = (new SchemaNormaliser)->normalise($decoded, $this->form->title);

        $result = (new SchemaValidator)->validate($normalised);

        if ($result->fails()) {
            $this->jsonErrors = $result->errors();

            return;
        }

        $this->schema = $normalised;
        $this->selectedFieldId = null;

        // Re-serialise so the textarea shows the normalised form, making the
        // repairs visible rather than surprising the user later.
        $this->syncJsonFromSchema();

        $this->touch();

        $this->dispatch('toast', message: 'Canvas updated from JSON.');
    }

    /** Canvas → JSON editor. */
    private function syncJsonFromSchema(): void
    {
        $this->rawJson = json_encode(
            $this->schemaForOutput(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    // ── AI editing ───────────────────────────────────────────────────────

    /**
     * The AI edit currently running, if any.
     */
    public function aiGeneration(): ?AiGeneration
    {
        if ($this->aiGenerationUuid === null) {
            return null;
        }

        return AiGeneration::where('uuid', $this->aiGenerationUuid)
            ->where('user_id', auth()->id())
            ->first();
    }

    /**
     * Ask the model to change this form.
     *
     * The schema sent is the SAVED one, not the unsaved canvas. Editing an
     * in-memory draft the user may still abandon would produce a version they
     * never asked for, and the model has no way to know which parts of the
     * draft were deliberate.
     */
    public function aiEdit(): void
    {
        $this->validate([
            'aiInstruction' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        if ($this->dirty) {
            $this->addError('aiInstruction', 'Save your changes first — the AI edits the saved version of the form.');

            return;
        }

        $generation = AiGeneration::create([
            'user_id' => auth()->id(),
            'form_id' => $this->form->id,
            'mode' => 'edit',
            'prompt' => trim($this->aiInstruction),
            'input_schema' => $this->form->schema(),
            'provider' => config('ai.provider'),
            'model' => config('ai.providers.'.config('ai.provider').'.model', 'unknown'),
            'status' => 'queued',
        ]);

        $this->aiGenerationUuid = $generation->uuid;

        RunAiGeneration::dispatch($generation->id);
    }

    /**
     * Polled while an AI edit runs. On success the job has already written the
     * next version, so the canvas just reloads from it.
     */
    public function pollAi(): void
    {
        $generation = $this->aiGeneration();

        if ($generation === null || ! $generation->isFinished()) {
            return;
        }

        if ($generation->succeeded()) {
            $this->form->refresh();
            $this->schema = $this->form->schema();
            $this->selectedFieldId = null;
            $this->dirty = false;

            $this->syncJsonFromSchema();
            $this->touch();
            $this->dirty = false;

            $this->aiInstruction = '';
            $this->aiGenerationUuid = null;

            $this->dispatch('toast', message: 'Form updated — saved as v'.$this->form->currentVersion->version_number.'.');
        }
    }

    public function dismissAi(): void
    {
        $this->aiGenerationUuid = null;
    }

    // ── Persistence ──────────────────────────────────────────────────────

    /**
     * Write the next version, but only if something actually changed.
     */
    public function save(bool $redirect = true)
    {
        $clean = $this->schemaForOutput();

        $result = (new SchemaValidator)->validate($clean);

        if ($result->fails()) {
            $this->jsonErrors = $result->errors();
            $this->tab = 'json';

            $this->dispatch('toast', message: 'Fix '.$result->count().' problem(s) before saving.', variant: 'danger');

            return null;
        }

        $checksum = FormVersion::checksumFor($clean);

        // Nothing changed: skip the write entirely rather than filling the
        // version history with identical rows.
        if ($this->form->currentVersion?->checksum === $checksum) {
            $this->dirty = false;
            $this->dispatch('toast', message: 'No changes to save.');

            return $redirect ? $this->redirectRoute('forms.index', navigate: true) : null;
        }

        DB::transaction(function () use ($clean, $checksum) {
            $next = ($this->form->versions()->max('version_number') ?? 0) + 1;

            $version = FormVersion::create([
                'form_id' => $this->form->id,
                'version_number' => $next,
                'schema' => $clean,
                'checksum' => $checksum,
                'origin' => 'manual',
                'created_by' => auth()->id(),
            ]);

            $this->form->update([
                'current_version_id' => $version->id,
                'title' => $clean['title'],
                'description' => $clean['description'],
            ]);
        });

        $this->form->refresh();
        $this->dirty = false;

        if ($redirect) {
            session()->flash('success', 'Form saved as v'.$this->form->currentVersion->version_number.'.');

            return $this->redirectRoute('forms.index', navigate: true);
        }

        $this->dispatch('toast', message: 'Saved as v'.$this->form->currentVersion->version_number.'.');

        return null;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * The schema stripped of editor-only bookkeeping.
     *
     * `_previous_label` exists so the key-regeneration rule can tell a
     * machine-derived key from a hand-edited one. It is UI state, not part of
     * the contract, so it never reaches the database.
     *
     * @return array<string, mixed>
     */
    private function schemaForOutput(): array
    {
        $schema = $this->schema;

        foreach ($schema['sections'] ?? [] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                unset($schema['sections'][$si]['fields'][$fi]['_previous_label']);
            }

            // Re-index in case a splice left gaps; JSON must encode these as
            // arrays, not objects.
            $schema['sections'][$si]['fields'] = array_values($schema['sections'][$si]['fields'] ?? []);
        }

        $schema['sections'] = array_values($schema['sections'] ?? []);

        return $schema;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function pathOf(string $fieldId): ?array
    {
        foreach ($this->schema['sections'] ?? [] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                if (($field['id'] ?? null) === $fieldId) {
                    return [$si, $fi];
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function allKeys(): array
    {
        $keys = [];

        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (! empty($field['key'])) {
                    $keys[] = $field['key'];
                }
            }
        }

        return $keys;
    }

    private function touch(): void
    {
        $this->dirty = true;

        // The computed properties cache per request; a mutation invalidates them.
        unset($this->selectedPath, $this->selectedField, $this->selectedType, $this->validation, $this->fieldCount);
    }

    public function render()
    {
        return view('livewire.forms.builder');
    }
}
