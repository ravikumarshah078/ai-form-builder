<?php

namespace App\Livewire\Forms;

use App\Enums\FormStatus;
use App\Forms\FormSchema;
use App\Models\Form;
use App\Models\FormVersion;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Steps 3 and 4 of the wizard: settings, then publish.
 *
 * Settings live inside the schema's `settings` object rather than on the
 * `forms` row, because they change what a respondent sees. Storing them on the
 * form would mean a submit button relabelled today silently applies to a
 * response captured last month; keeping them versioned means a submission can
 * always be replayed exactly as it was presented.
 *
 * The exception is `status`, which is genuinely a property of the form's
 * identity rather than its shape, so it stays on the row.
 */
#[Layout('layouts.app')]
class Settings extends Component
{
    public Form $form;

    public bool $multiStep = false;

    public string $submitLabel = '';

    public string $successMessage = '';

    public ?string $redirectUrl = null;

    public function mount(Form $form): void
    {
        abort_unless($form->user_id === auth()->id(), 403);

        $this->form = $form;

        $settings = FormSchema::wrap($form->schema())->settings();

        $this->multiStep = (bool) $settings['multi_step'];
        $this->submitLabel = (string) $settings['submit_label'];
        $this->successMessage = (string) $settings['success_message'];
        $this->redirectUrl = $settings['redirect_url'];
    }

    protected function rules(): array
    {
        return [
            'submitLabel' => ['required', 'string', 'max:60'],
            'successMessage' => ['required', 'string', 'max:500'],
            // Anything other than http(s) here would be an open redirect, and
            // javascript: would be stored XSS on the thank-you page.
            'redirectUrl' => ['nullable', 'url', 'starts_with:http://,https://', 'max:2000'],
        ];
    }

    #[Computed]
    public function schema(): FormSchema
    {
        return FormSchema::wrap($this->form->schema());
    }

    #[Computed]
    public function readyToPublish(): bool
    {
        return $this->schema()->answerFields() !== [] && $this->schema()->isValid();
    }

    /**
     * Persist settings as a new schema version.
     *
     * @return array<string, mixed>|null  the saved schema, or null on failure
     */
    private function persist(): ?array
    {
        $this->validate();

        $schema = $this->form->schema();

        $schema['settings'] = [
            'multi_step' => $this->multiStep,
            'submit_label' => trim($this->submitLabel),
            'success_message' => trim($this->successMessage),
            'redirect_url' => $this->redirectUrl ?: null,
        ];

        $checksum = FormVersion::checksumFor($schema);

        if ($this->form->currentVersion?->checksum === $checksum) {
            return $schema;
        }

        DB::transaction(function () use ($schema, $checksum) {
            $next = ($this->form->versions()->max('version_number') ?? 0) + 1;

            $version = FormVersion::create([
                'form_id' => $this->form->id,
                'version_number' => $next,
                'schema' => $schema,
                'checksum' => $checksum,
                'origin' => 'manual',
                'note' => 'Settings updated',
                'created_by' => auth()->id(),
            ]);

            $this->form->update(['current_version_id' => $version->id]);
        });

        $this->form->refresh();

        return $schema;
    }

    public function save(): void
    {
        if ($this->persist() === null) {
            return;
        }

        $this->dispatch('toast', message: 'Settings saved.');
    }

    /**
     * Make the form live.
     *
     * Publishing an empty form would give the world a URL that collects
     * nothing, so it is blocked rather than merely discouraged.
     */
    public function publish()
    {
        if ($this->persist() === null) {
            return null;
        }

        if (! $this->readyToPublish()) {
            $this->addError('publish', 'Add at least one field that collects an answer before publishing.');

            return null;
        }

        $this->form->update([
            'status' => FormStatus::Published,
            'published_at' => $this->form->published_at ?? now(),
        ]);

        session()->flash('success', 'Form published. It is now live at '.$this->form->publicUrl());

        return $this->redirectRoute('forms.index', navigate: true);
    }

    public function unpublish(): void
    {
        $this->form->update(['status' => FormStatus::Draft]);
        $this->form->refresh();

        $this->dispatch('toast', message: 'Form unpublished. The public URL now returns 404.');
    }

    public function render()
    {
        return view('livewire.forms.settings');
    }
}
