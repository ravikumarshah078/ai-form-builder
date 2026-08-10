<?php

namespace App\Livewire\Imports;

use App\Enums\FieldType;
use App\Enums\FormStatus;
use App\Forms\SchemaNormaliser;
use App\Forms\SchemaValidator;
use App\Models\DocumentImport;
use App\Models\Form;
use App\Models\FormVersion;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The preview and mapping screen.
 *
 * The brief: "Show a preview and mapping screen before committing so the user
 * can fix a wrongly detected field type."
 *
 * Two things make this more than a confirmation dialog:
 *
 *   - Every field's type is editable here, and every change is written back
 *     through SchemaNormaliser, so a user correction is validated exactly like
 *     a parser or AI result. There is no privileged path into the schema.
 *
 *   - The deterministic result is kept alongside the AI-refined one, so
 *     "revert to what the parser actually found" works without re-uploading.
 *     That is also what lets the screen show WHERE each type came from.
 */
#[Layout('layouts.app')]
class Review extends Component
{
    public DocumentImport $import;

    /** The schema being reviewed; edits mutate this. */
    public array $schema = [];

    public string $title = '';

    public bool $showWarnings = false;

    public function mount(DocumentImport $import): void
    {
        abort_unless($import->user_id === auth()->id(), 403);
        abort_unless($import->workingSchema() !== null, 404);

        $this->import = $import;
        $this->schema = $import->workingSchema();
        $this->title = $this->schema['title'] ?? 'Imported form';
    }

    #[Computed]
    public function types(): array
    {
        return FieldType::cases();
    }

    /**
     * Where each field's type came from, for the "Detected by" column.
     *
     * @return array<string, array{confidence: string, reason: string, source: string}>
     */
    #[Computed]
    public function detections(): array
    {
        return $this->import->stats['detections'] ?? [];
    }

    #[Computed]
    public function validation()
    {
        return (new SchemaValidator)->validate($this->schema);
    }

    /**
     * Change one field's type.
     *
     * Routed through the normaliser rather than assigning directly, because
     * switching to a choice type needs options invented and switching away
     * needs them removed. That logic already exists; duplicating it here is how
     * the two would drift.
     */
    public function setType(string $fieldId, string $type): void
    {
        $newType = FieldType::tryFrom($type);

        if ($newType === null) {
            return;
        }

        foreach ($this->schema['sections'] ?? [] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                if (($field['id'] ?? null) !== $fieldId) {
                    continue;
                }

                $this->schema['sections'][$si]['fields'][$fi]['type'] = $newType->value;

                if (! $newType->hasOptions()) {
                    $this->schema['sections'][$si]['fields'][$fi]['options'] = [];
                }

                if (! $newType->collectsAnswer()) {
                    $this->schema['sections'][$si]['fields'][$fi]['key'] = null;
                    $this->schema['sections'][$si]['fields'][$fi]['required'] = false;
                }
            }
        }

        $this->schema = (new SchemaNormaliser)->normalise($this->schema, $this->title);

        unset($this->validation);
    }

    public function toggleRequired(string $fieldId): void
    {
        foreach ($this->schema['sections'] ?? [] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                if (($field['id'] ?? null) === $fieldId && FieldType::tryFrom($field['type'])?->collectsAnswer()) {
                    $this->schema['sections'][$si]['fields'][$fi]['required'] = ! ($field['required'] ?? false);
                }
            }
        }

        unset($this->validation);
    }

    public function removeField(string $fieldId): void
    {
        foreach ($this->schema['sections'] ?? [] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                if (($field['id'] ?? null) === $fieldId) {
                    array_splice($this->schema['sections'][$si]['fields'], $fi, 1);
                }
            }
        }

        // Drop any section left with nothing in it.
        $this->schema['sections'] = array_values(array_filter(
            $this->schema['sections'] ?? [],
            fn ($s) => ! empty($s['fields'])
        ));

        unset($this->validation);
    }

    /**
     * Throw away AI suggestions and user edits, back to the raw parse.
     */
    public function revertToParsed(): void
    {
        $this->schema = $this->import->parsed_schema ?? $this->schema;
        $this->title = $this->schema['title'] ?? $this->title;

        unset($this->validation);

        $this->dispatch('toast', message: 'Reverted to what the parser detected.');
    }

    /**
     * Create the form. The only place an import becomes real.
     */
    public function commit()
    {
        $schema = $this->schema;
        $schema['title'] = trim($this->title) ?: 'Imported form';

        $schema = (new SchemaNormaliser)->normalise($schema, $schema['title']);

        $result = (new SchemaValidator)->validate($schema);

        if ($result->fails()) {
            $this->addError('commit', 'Fix these before creating the form: '.implode(' ', $result->messages()));

            return null;
        }

        $form = DB::transaction(function () use ($schema) {
            $form = Form::create([
                'user_id' => auth()->id(),
                'title' => $schema['title'],
                'description' => $schema['description'] ?? null,
                // Imported forms are drafts. A document turned into a live
                // public URL without anyone looking at it would be alarming.
                'status' => FormStatus::Draft,
            ]);

            $version = FormVersion::create([
                'form_id' => $form->id,
                'version_number' => 1,
                'schema' => $schema,
                'checksum' => FormVersion::checksumFor($schema),
                'origin' => 'import',
                'note' => 'Imported from '.$this->import->original_filename,
                'created_by' => auth()->id(),
            ]);

            $form->update(['current_version_id' => $version->id]);

            $this->import->update([
                'status' => 'committed',
                'form_id' => $form->id,
                'proposed_schema' => $schema,
            ]);

            return $form;
        });

        session()->flash('success', 'Form created from '.$this->import->original_filename.'. Review it below.');

        return $this->redirectRoute('forms.build', ['form' => $form], navigate: true);
    }

    public function render()
    {
        return view('livewire.imports.review');
    }
}
