<?php

namespace App\Livewire\Forms;

use App\Jobs\RunAiGeneration;
use App\Models\AiGeneration;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Generate a form from a natural-language prompt.
 *
 * The web request never waits on the model. Submitting writes an AiGeneration
 * row, dispatches a job and returns immediately; the browser then polls that
 * same row. Status is "visible" in the brief's sense because the audit record
 * and the progress indicator are the same object.
 */
#[Layout('layouts.app')]
class AiGenerate extends Component
{
    #[Validate('required|string|min:10|max:2000')]
    public string $prompt = '';

    /** Set once a generation is in flight; the view polls on it. */
    public ?string $generationUuid = null;

    /**
     * Example prompts, including the one from the brief. These exist because a
     * blank prompt box is the hardest possible starting point — people write
     * far better prompts when shown the expected shape and level of detail.
     *
     * @var array<int, string>
     */
    public array $examples = [
        'An internship application with education history, skills and resume upload',
        'A customer feedback survey with a 1-5 rating, what went well, what could improve, and an optional email for follow-up',
        'An event registration form with attendee details, dietary requirements, session choices and a t-shirt size',
        'A bug report form with severity, steps to reproduce, expected and actual behaviour, and a screenshot upload',
    ];

    public function mount(): void
    {
        // Resume a generation that was still running when the page reloaded,
        // rather than silently losing it.
        $running = AiGeneration::where('user_id', auth()->id())
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($running !== null) {
            $this->generationUuid = $running->uuid;
        }
    }

    public function useExample(int $index): void
    {
        $this->prompt = $this->examples[$index] ?? $this->prompt;
    }

    /**
     * The generation currently being watched.
     */
    public function generation(): ?AiGeneration
    {
        if ($this->generationUuid === null) {
            return null;
        }

        return AiGeneration::where('uuid', $this->generationUuid)
            ->where('user_id', auth()->id())
            ->first();
    }

    public function generate(): void
    {
        $this->validate();

        $generation = AiGeneration::create([
            'user_id' => auth()->id(),
            'mode' => 'create',
            'prompt' => trim($this->prompt),
            'provider' => config('ai.provider'),
            'model' => config('ai.providers.'.config('ai.provider').'.model', 'unknown'),
            'status' => 'queued',
        ]);

        $this->generationUuid = $generation->uuid;

        RunAiGeneration::dispatch($generation->id);
    }

    /**
     * Called by wire:poll while a generation is in flight.
     *
     * Returns nothing: re-rendering is the point, and the view reads the
     * current state straight from the row.
     */
    public function poll()
    {
        $generation = $this->generation();

        if ($generation === null || ! $generation->isFinished()) {
            return null;
        }

        // Finished and it produced a form: go straight to the builder so the
        // user lands on something they can edit, which is what the brief means
        // by "fully editable".
        if ($generation->succeeded() && $generation->form_id !== null) {
            session()->flash('success', 'Form generated. Review and edit it below.');

            return $this->redirectRoute('forms.build', ['form' => $generation->form], navigate: true);
        }

        return null;
    }

    public function reset_(): void
    {
        $this->generationUuid = null;
        $this->prompt = '';
    }

    public function render()
    {
        return view('livewire.forms.ai-generate', [
            'generation' => $this->generation(),
        ]);
    }
}
