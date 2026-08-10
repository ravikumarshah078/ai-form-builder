{{--
    Step 1 of the builder wizard, matching the reference UI in the brief:
    a stepper across the top, a "Form basics" card, and Cancel / Next actions.

    Steps 2-4 are rendered but inert until their screens exist. Showing the
    whole path up front tells the user how long the journey is, which is the
    entire reason the reference design has a stepper.
--}}
<div>
    @section('title', $form ? 'Edit form' : 'New form')
    @section('heading', 'Form Builder')

    {{-- Stepper --}}
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                @php
                    $steps = ['Details', 'Builder', 'Settings', 'Finish'];
                    $active = 1;
                @endphp

                @foreach ($steps as $i => $label)
                    @php $n = $i + 1; @endphp

                    <div class="d-flex align-items-center gap-2 {{ $n === $active ? '' : 'text-body-secondary' }}">
                        <span class="badge rounded-circle {{ $n === $active ? 'bg-primary' : 'bg-secondary-subtle text-secondary' }}"
                              style="width:28px;height:28px;line-height:20px;">
                            {{ $n }}
                        </span>
                        <span class="{{ $n === $active ? 'fw-semibold' : '' }}">{{ $label }}</span>
                    </div>

                    @if (! $loop->last)
                        <div class="flex-grow-1 border-top mx-2 d-none d-md-block"></div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    <form wire:submit="save">
        <div class="row">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-body">

                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="mb-1">Form basics</h5>
                                <p class="text-body-secondary mb-0">
                                    Enter the primary details for your new data-collection form.
                                </p>
                            </div>
                        </div>

                        {{-- Title --}}
                        <div class="mb-3">
                            <label for="title" class="form-label">
                                Form title <span class="text-danger">*</span>
                            </label>

                            <input type="text"
                                   id="title"
                                   maxlength="200"
                                   class="form-control @error('title') is-invalid @enderror"
                                   placeholder="e.g., Fall 2024 Registration"
                                   wire:model.live.debounce.300ms="title">

                            @error('title')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror

                            <div class="form-text d-flex justify-content-between">
                                <span>{{ strlen($title) }}/200</span>
                            </div>
                        </div>

                        {{-- Description --}}
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>

                            <textarea id="description"
                                      rows="3"
                                      class="form-control @error('description') is-invalid @enderror"
                                      placeholder="Shown to respondents above the first question."
                                      wire:model.blur="description"></textarea>

                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Public URL preview --}}
                        <div class="border rounded p-2 bg-body-tertiary">
                            <small class="text-body-secondary d-block">Public URL</small>
                            <code class="small">{{ $this->publicUrlPreview() }}</code>

                            @unless ($form)
                                <small class="d-block text-body-secondary mt-1">
                                    Preview only &mdash; the final address is confirmed on save
                                    if this one is already taken.
                                </small>
                            @endunless
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="d-flex justify-content-between mt-4">
            <a href="{{ route('forms.index') }}" class="btn btn-outline-danger" wire:navigate>
                Cancel
            </a>

            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Next: Builder &rarr;</span>
                <span wire:loading wire:target="save">Saving&hellip;</span>
            </button>
        </div>
    </form>
</div>
