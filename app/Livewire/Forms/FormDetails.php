<?php

namespace App\Livewire\Forms;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Step 1 of the builder wizard: form basics.
 *
 * Mounted for both "create" (no form bound) and "edit" (existing form), so the
 * title/description editing rules live in exactly one place.
 *
 * Livewire rather than a plain controller because the live slug preview and
 * the character counter both need server state — the slug has to be checked
 * for uniqueness against the database, which a purely client-side preview
 * cannot do.
 */
#[Layout('layouts.app')]
class FormDetails extends Component
{
    public ?Form $form = null;

    #[Validate('required|string|min:3|max:200')]
    public string $title = '';

    #[Validate('nullable|string|max:2000')]
    public string $description = '';

    /**
     * Route-model bound on the edit route, absent on the create route.
     */
    public function mount(?Form $form = null): void
    {
        if ($form?->exists) {
            abort_unless($form->user_id === auth()->id(), 403);

            $this->form = $form;
            $this->title = $form->title;
            $this->description = (string) $form->description;
        }
    }

    /**
     * Live preview of the public URL as the user types.
     *
     * Deliberately NOT the value we persist. On save, Form::generateUniqueSlug
     * re-derives it and resolves collisions; this is a preview only, and it
     * says so in the view.
     */
    public function publicUrlPreview(): string
    {
        $slug = $this->form?->slug ?: (Str::slug($this->title) ?: 'your-form');

        return url('/f/'.$slug);
    }

    /**
     * Persist and move on.
     *
     * Wrapped in a transaction because creating a form is genuinely two
     * writes: the form row, and its first schema version. A form that exists
     * with no version would render as a 404 on its own public URL, so the two
     * must land together or not at all.
     */
    public function save()
    {
        $this->validate();

        $form = DB::transaction(function () {
            if ($this->form) {
                $this->form->update([
                    'title' => $this->title,
                    'description' => $this->description ?: null,
                ]);

                return $this->form;
            }

            $form = Form::create([
                'user_id' => auth()->id(),
                'title' => $this->title,
                'description' => $this->description ?: null,
                'status' => FormStatus::Draft,
            ]);

            // Every form gets a v1 immediately, even though it has no fields
            // yet. That keeps `current_version_id` non-null for the whole life
            // of the form, so no downstream code has to special-case "a form
            // that has never been saved".
            $schema = [
                'version' => 1,
                'title' => $form->title,
                'description' => $form->description,
                'settings' => ['multi_step' => false],
                'sections' => [],
            ];

            $version = FormVersion::create([
                'form_id' => $form->id,
                'version_number' => 1,
                'schema' => $schema,
                'checksum' => FormVersion::checksumFor($schema),
                'origin' => 'manual',
                'created_by' => auth()->id(),
            ]);

            $form->update(['current_version_id' => $version->id]);

            return $form;
        });

        return $this->redirectRoute('forms.build', ['form' => $form], navigate: true);
    }

    public function render()
    {
        return view('livewire.forms.form-details');
    }
}
