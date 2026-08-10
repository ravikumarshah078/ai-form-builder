@php $aiGeneration = $this->aiGeneration(); @endphp

{{--
    AI editing of an EXISTING form.

    The brief calls this out specifically: "Support AI editing of an existing
    form, not just creation from zero." The three examples it gives are the
    three suggestion buttons below, so the demo path is obvious.
--}}
<div @if ($aiGeneration && ! $aiGeneration->isFinished()) wire:poll.1500ms="pollAi" @endif>

    @if ($aiGeneration && ! $aiGeneration->isFinished())

        <div class="text-center py-4">
            <div class="spinner-border text-primary mb-3" role="status">
                <span class="visually-hidden">Working…</span>
            </div>
            <p class="mb-1 fw-semibold">Applying your change…</p>
            <p class="small text-body-secondary mb-3">
                {{ $aiGeneration->model }} &middot; attempt {{ max(1, $aiGeneration->attempts) }}
            </p>
            <div class="alert alert-light border small text-start mb-0">
                {{ $aiGeneration->prompt }}
            </div>
        </div>

    @elseif ($aiGeneration && ! $aiGeneration->succeeded())

        <div class="alert alert-danger small">
            <strong>That edit failed.</strong>
            <p class="mb-2 mt-1">{{ $aiGeneration->error }}</p>
            <p class="mb-0 text-body-secondary">
                Your form is untouched — a version is only written after the new
                schema passes validation.
            </p>
        </div>

        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="dismissAi">
            Try again
        </button>

    @else

        <p class="small text-body-secondary">
            Describe a change in plain language. The AI edits the
            <strong>saved</strong> version and writes the result as a new version,
            so nothing is overwritten and you can always roll back.
        </p>

        <form wire:submit="aiEdit">
            <div class="mb-2">
                <textarea rows="3" maxlength="1000"
                          class="form-control form-control-sm @error('aiInstruction') is-invalid @enderror"
                          placeholder="e.g. add an emergency contact section"
                          wire:model="aiInstruction"></textarea>
                @error('aiInstruction')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <button type="submit" class="btn btn-sm btn-primary w-100" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="aiEdit">Apply with AI</span>
                <span wire:loading wire:target="aiEdit">Starting…</span>
            </button>
        </form>

        <div class="mt-3">
            <div class="small fw-semibold text-body-secondary text-uppercase mb-2">Examples</div>

            @foreach ([
                'Add an emergency contact section',
                'Make the phone number required',
                'Translate all labels to Hindi',
                'Add a field for expected salary',
            ] as $example)
                <button type="button"
                        class="btn btn-sm btn-outline-secondary text-start w-100 mb-1"
                        wire:click="$set('aiInstruction', @js($example))">
                    {{ $example }}
                </button>
            @endforeach
        </div>

        @if ($dirty)
            <div class="alert alert-warning small mt-3 mb-0">
                You have unsaved changes. Save them first — the AI works from the
                saved version.
            </div>
        @endif

        <div class="border-top mt-3 pt-2 small text-body-secondary">
            Provider <code>{{ config('ai.provider') }}</code> &middot;
            <code>{{ config('ai.providers.'.config('ai.provider').'.model', 'n/a') }}</code>
        </div>
    @endif
</div>
