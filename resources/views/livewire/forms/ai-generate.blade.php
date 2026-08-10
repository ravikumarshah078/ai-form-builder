{{--
    Generate a form from a prompt.

    While a generation is in flight the component polls its AiGeneration row.
    That row is both the audit log and the progress state, so there is no
    separate job-status mechanism to keep in step.
--}}
<div @if ($generation && ! $generation->isFinished()) wire:poll.1500ms="poll" @endif>
    @section('title', 'Generate with AI')
    @section('heading', 'Generate a form with AI')

    <div class="row g-3">
        <div class="col-lg-7">

            {{-- ════ In flight ════ --}}
            @if ($generation && ! $generation->isFinished())

                <div class="card">
                    <div class="card-body text-center py-5">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Working…</span>
                        </div>

                        <h5 class="mb-1">
                            {{ $generation->status === 'queued' ? 'Queued…' : 'Designing your form…' }}
                        </h5>

                        <p class="text-body-secondary mb-3">
                            {{ $generation->model }} &middot; attempt {{ max(1, $generation->attempts) }}
                        </p>

                        <div class="alert alert-light border small text-start mb-0">
                            <strong>Prompt</strong><br>
                            {{ $generation->prompt }}
                        </div>
                    </div>
                </div>

            {{-- ════ Failed ════ --}}
            @elseif ($generation && ! $generation->succeeded())

                <div class="card border-danger">
                    <div class="card-body">
                        <h5 class="text-danger mb-2">Generation failed</h5>

                        <p class="small mb-3">{{ $generation->error }}</p>

                        <div class="small text-body-secondary mb-3">
                            {{ $generation->model }} &middot;
                            {{ $generation->attempts }} attempt(s) &middot;
                            {{ number_format($generation->latency_ms) }} ms &middot;
                            {{ number_format($generation->totalTokens()) }} tokens
                        </div>

                        <p class="small text-body-secondary">
                            Nothing was saved. A schema is only ever persisted after it
                            passes validation, so a failed generation cannot leave a
                            broken form behind.
                        </p>

                        <button type="button" class="btn btn-outline-primary" wire:click="reset_">
                            Try again
                        </button>
                    </div>
                </div>

            {{-- ════ Prompt form ════ --}}
            @else

                <div class="card">
                    <div class="card-body">
                        <h5 class="mb-1">Describe the form you need</h5>
                        <p class="text-body-secondary">
                            Say what it should collect and how it should be grouped.
                            The more specific you are, the better the field types and
                            validation will be.
                        </p>

                        <form wire:submit="generate">
                            <div class="mb-3">
                                <textarea rows="4" maxlength="2000"
                                          class="form-control @error('prompt') is-invalid @enderror"
                                          placeholder="e.g. An internship application with education history, skills and resume upload"
                                          wire:model="prompt"></textarea>
                                @error('prompt')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="generate">Generate form</span>
                                <span wire:loading wire:target="generate">Starting…</span>
                            </button>

                            <a href="{{ route('forms.create') }}" class="btn btn-outline-secondary" wire:navigate>
                                Build manually instead
                            </a>
                        </form>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-body">
                        <div class="small fw-semibold text-body-secondary text-uppercase mb-2">
                            Try one of these
                        </div>

                        @foreach ($examples as $i => $example)
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary text-start w-100 mb-2"
                                    wire:click="useExample({{ $i }})">
                                {{ $example }}
                            </button>
                        @endforeach
                    </div>
                </div>

            @endif
        </div>

        {{-- ════ How it works ════ --}}
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-3">How this works</h6>

                    <ol class="small ps-3 mb-3">
                        <li class="mb-2">
                            The request returns immediately and the work runs on a queue,
                            so a slow model never holds a web worker.
                        </li>
                        <li class="mb-2">
                            The model is constrained at the API level by a JSON schema whose
                            field-type list is generated from the application's own
                            <code>FieldType</code> enum — it cannot invent a type we do not
                            implement.
                        </li>
                        <li class="mb-2">
                            The reply is repaired mechanically where it is unambiguous
                            (<code>select</code> becomes <code>dropdown</code>), then validated.
                        </li>
                        <li class="mb-2">
                            If it still fails, the exact errors are fed back and a correction
                            is requested, up to {{ config('ai.max_attempts') }} attempts.
                        </li>
                        <li>
                            Only a schema that passes validation is ever written to the
                            database.
                        </li>
                    </ol>

                    <div class="border-top pt-2 small text-body-secondary">
                        Provider: <code>{{ config('ai.provider') }}</code><br>
                        Model: <code>{{ config('ai.providers.'.config('ai.provider').'.model', 'n/a') }}</code>

                        @if (config('ai.provider') === 'fake')
                            <div class="alert alert-warning py-2 mt-2 mb-0">
                                No API key configured, so responses are canned but
                                schema-valid. Set <code>GEMINI_API_KEY</code> for real
                                generation.
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Recent generations: the observability the brief asks for. --}}
            @php
                $recent = \App\Models\AiGeneration::where('user_id', auth()->id())
                    ->latest('id')->limit(5)->get();
            @endphp

            @if ($recent->isNotEmpty())
                <div class="card mt-3">
                    <div class="card-body">
                        <h6 class="mb-3">Recent generations</h6>
                        <div class="table-responsive">
                            <table class="table table-sm small mb-0">
                                <thead>
                                    <tr>
                                        <th>Status</th><th>Mode</th><th class="text-end">Tries</th>
                                        <th class="text-end">Tokens</th><th class="text-end">Latency</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach ($recent as $row)
                                    <tr>
                                        <td>
                                            <span class="badge {{ $row->succeeded() ? 'bg-success' : ($row->isFinished() ? 'bg-danger' : 'bg-secondary') }}">
                                                {{ $row->status }}
                                            </span>
                                        </td>
                                        <td>{{ $row->mode }}</td>
                                        <td class="text-end">{{ $row->attempts }}</td>
                                        <td class="text-end">{{ number_format($row->totalTokens()) }}</td>
                                        <td class="text-end">{{ number_format($row->latency_ms) }} ms</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
