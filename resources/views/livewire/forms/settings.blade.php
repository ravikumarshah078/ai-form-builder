{{-- Steps 3 and 4 of the wizard: settings, then publish. --}}
<div>
    @section('title', 'Form settings')
    @section('heading', 'Form Builder')

    {{-- Stepper --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                @foreach (['Details', 'Builder', 'Settings', 'Finish'] as $i => $label)
                    @php $n = $i + 1; $active = $form->isPublished() ? 4 : 3; @endphp
                    <div class="d-flex align-items-center gap-2 {{ $n === $active ? '' : 'text-body-secondary' }}">
                        <span class="badge rounded-circle {{ $n < $active ? 'bg-success' : ($n === $active ? 'bg-primary' : 'bg-secondary-subtle text-secondary') }}"
                              style="width:28px;height:28px;line-height:20px;">
                            {{ $n < $active ? '✓' : $n }}
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

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Settings</h5>

                    {{-- Multi-step --}}
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="multiStep" wire:model.live="multiStep">
                        <label class="form-check-label" for="multiStep">
                            Show each section as a separate step
                        </label>
                        <div class="form-text">
                            {{ $this->schema->sectionCount() }} section(s). With this off, the whole
                            form renders on one page.
                        </div>
                    </div>

                    {{-- Submit label --}}
                    <div class="mb-3">
                        <label for="submitLabel" class="form-label">Submit button label</label>
                        <input type="text" id="submitLabel" maxlength="60"
                               class="form-control @error('submitLabel') is-invalid @enderror"
                               wire:model.blur="submitLabel">
                        @error('submitLabel')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Success message --}}
                    <div class="mb-3">
                        <label for="successMessage" class="form-label">Message after submitting</label>
                        <textarea id="successMessage" rows="2" maxlength="500"
                                  class="form-control @error('successMessage') is-invalid @enderror"
                                  wire:model.blur="successMessage"></textarea>
                        @error('successMessage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Redirect --}}
                    <div class="mb-3">
                        <label for="redirectUrl" class="form-label">Redirect after submitting <span class="text-body-secondary">(optional)</span></label>
                        <input type="url" id="redirectUrl" placeholder="https://example.com/thanks"
                               class="form-control @error('redirectUrl') is-invalid @enderror"
                               wire:model.blur="redirectUrl">
                        @error('redirectUrl')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Must be http or https. Leave blank to show the message above instead.
                        </div>
                    </div>

                    <button type="button" class="btn btn-outline-primary" wire:click="save">
                        Save settings
                    </button>
                </div>
            </div>
        </div>

        {{-- Publish panel --}}
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Publish</h5>

                    <div class="mb-3">
                        <span class="badge {{ $form->status->badgeClass() }}">{{ $form->status->label() }}</span>
                        <span class="badge bg-secondary-subtle text-secondary">
                            v{{ $form->currentVersion?->version_number ?? 1 }}
                        </span>
                        <span class="badge bg-secondary-subtle text-secondary">
                            {{ count($this->schema->answerFields()) }} answerable field(s)
                        </span>
                    </div>

                    @error('publish')
                        <div class="alert alert-danger py-2 small">{{ $message }}</div>
                    @enderror

                    @if (! $this->readyToPublish)
                        <div class="alert alert-warning py-2 small">
                            This form has nothing that collects an answer yet. Add a field
                            in the builder before publishing.
                        </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Public URL</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control font-monospace"
                                   value="{{ $form->publicUrl() }}" readonly
                                   id="public-url-input">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="navigator.clipboard.writeText(document.getElementById('public-url-input').value); this.textContent='Copied';">
                                Copy
                            </button>
                        </div>
                        @unless ($form->isPublished())
                            <div class="form-text small">
                                This URL returns 404 until the form is published.
                            </div>
                        @endunless
                    </div>

                    @if ($form->isPublished())
                        <a href="{{ $form->publicUrl() }}" target="_blank" rel="noopener"
                           class="btn btn-success w-100 mb-2">Open live form ↗</a>
                        <button type="button" class="btn btn-outline-danger w-100" wire:click="unpublish"
                                wire:confirm="Unpublish? The public URL will start returning 404.">
                            Unpublish
                        </button>
                    @else
                        <button type="button" class="btn btn-primary w-100"
                                wire:click="publish" @disabled(! $this->readyToPublish)>
                            Publish form
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between mt-4">
        <a href="{{ route('forms.build', $form) }}" class="btn btn-outline-secondary" wire:navigate>
            ← Back: Builder
        </a>
        <a href="{{ route('forms.submissions', $form) }}" class="btn btn-outline-primary" wire:navigate>
            View responses →
        </a>
    </div>
</div>
