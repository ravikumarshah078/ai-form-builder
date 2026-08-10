@php use App\Enums\FieldType; @endphp

{{--
    The preview and mapping screen — the brief's required pause before anything
    is committed.

    The "Detected by" column is the visible half of the hybrid: it says, per
    field, whether the type came from the document itself or from a model, and
    why. That is what lets a user trust the ones marked "declared in the sheet"
    and scrutinise the ones marked "AI changed this".
--}}
<div>
    @section('title', 'Review import')
    @section('heading', 'Review before creating the form')

    @php $stats = $import->stats ?? []; @endphp

    {{-- Summary --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div>
                    <div class="fw-semibold">{{ $import->original_filename }}</div>
                    <small class="text-body-secondary">
                        {{ number_format($import->size / 1024, 1) }} KB &middot;
                        layout <code>{{ $stats['layout'] ?? $import->source }}</code>
                    </small>
                </div>

                <div class="ms-auto d-flex flex-wrap gap-2">
                    <span class="badge bg-secondary-subtle text-secondary">
                        {{ $stats['sections'] ?? 0 }} sections
                    </span>
                    <span class="badge bg-secondary-subtle text-secondary">
                        {{ $stats['fields'] ?? 0 }} fields
                    </span>
                    <span class="badge bg-success-subtle text-success">
                        {{ $stats['confident'] ?? 0 }} confident
                    </span>

                    @if (($stats['ai_considered'] ?? 0) > 0)
                        <span class="badge bg-info-subtle text-info-emphasis">
                            {{ $stats['ai_considered'] }} sent to AI
                            @if (! empty($stats['ai_tokens']))
                                ({{ number_format($stats['ai_tokens']) }} tokens)
                            @endif
                        </span>
                    @else
                        <span class="badge bg-light text-body-secondary">AI not needed</span>
                    @endif

                    @if (! empty($import->warnings))
                        <button type="button" class="badge bg-warning-subtle text-warning-emphasis border-0"
                                wire:click="$toggle('showWarnings')">
                            {{ count($import->warnings) }} warnings
                        </button>
                    @endif
                </div>
            </div>

            @if ($showWarnings && ! empty($import->warnings))
                <div class="alert alert-warning small mt-3 mb-0">
                    <div class="fw-semibold mb-2">Blocks we could not interpret</div>
                    <ul class="mb-0 ps-3">
                        @foreach ($import->warnings as $warning)
                            <li>
                                {{ $warning['message'] }}
                                @if (! empty($warning['excerpt']))
                                    <br><code class="small">{{ Str::limit($warning['excerpt'], 120) }}</code>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    @error('commit')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    {{-- Title --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <label for="import-title" class="form-label mb-1">Form title</label>
            <input type="text" id="import-title" maxlength="200" class="form-control"
                   value="{{ $title }}"
                   wire:model.live.debounce.400ms="title">
        </div>
    </div>

    {{-- Field mapping --}}
    @forelse ($schema['sections'] ?? [] as $si => $section)
        <div class="card mb-3" wire:key="sec-{{ $section['id'] }}">
            <div class="card-header py-2">
                <input type="text" class="form-control form-control-sm border-0 fw-semibold bg-transparent"
                       placeholder="Untitled section"
                       value="{{ $section['title'] }}"
                       wire:model.live.debounce.400ms="schema.sections.{{ $si }}.title">
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:34%">Question</th>
                            <th style="width:18%">Type</th>
                            <th style="width:10%" class="text-center">Required</th>
                            <th style="width:30%">Detected by</th>
                            <th style="width:8%"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($section['fields'] ?? [] as $fi => $field)
                        @php
                            $detection = $this->detections[$field['id']] ?? null;
                            $type = FieldType::tryFrom($field['type']);
                        @endphp

                        <tr wire:key="fld-{{ $field['id'] }}">
                            <td>
                                {{--
                                    value= is set explicitly as well as wire:model.
                                    Livewire hydrates bound inputs client-side and does
                                    not server-render their value, so without this the
                                    whole preview is blank until JavaScript boots — on a
                                    screen whose entire job is showing what was detected.
                                --}}
                                <input type="text" class="form-control form-control-sm"
                                       value="{{ $field['label'] }}"
                                       wire:model.live.debounce.500ms="schema.sections.{{ $si }}.fields.{{ $fi }}.label">

                                @if (! empty($field['options']))
                                    <small class="text-body-secondary">
                                        {{ count($field['options']) }} options:
                                        {{ Str::limit(collect($field['options'])->pluck('label')->implode(', '), 60) }}
                                    </small>
                                @endif
                            </td>

                            <td>
                                {{-- The correction the brief asks for. --}}
                                <select class="form-select form-select-sm"
                                        wire:change="setType('{{ $field['id'] }}', $event.target.value)">
                                    @foreach ($this->types as $option)
                                        <option value="{{ $option->value }}" @selected($field['type'] === $option->value)>
                                            {{ $option->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>

                            <td class="text-center">
                                @if ($type?->collectsAnswer())
                                    <input type="checkbox" class="form-check-input"
                                           @checked(! empty($field['required']))
                                           wire:click="toggleRequired('{{ $field['id'] }}')">
                                @else
                                    <span class="text-body-secondary">&mdash;</span>
                                @endif
                            </td>

                            <td>
                                @if ($detection)
                                    <span class="badge {{ $detection['source'] === 'ai' ? 'bg-info-subtle text-info-emphasis' : 'bg-secondary-subtle text-secondary' }}">
                                        {{ $detection['source'] === 'ai' ? 'AI' : 'document' }}
                                    </span>
                                    <small class="text-body-secondary">{{ $detection['reason'] }}</small>
                                @else
                                    <small class="text-body-secondary">edited by you</small>
                                @endif
                            </td>

                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        wire:click="removeField('{{ $field['id'] }}')"
                                        title="Remove this field">&times;</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="alert alert-warning">
            Every field was removed. Revert, or go back and upload a different document.
        </div>
    @endforelse

    {{-- Actions --}}
    <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-2">
        <div>
            <a href="{{ route('imports.create') }}" class="btn btn-outline-secondary" wire:navigate>Cancel</a>

            @if ($import->parsed_schema)
                <button type="button" class="btn btn-outline-secondary" wire:click="revertToParsed"
                        title="Discard AI suggestions and your edits">
                    Revert to raw parse
                </button>
            @endif
        </div>

        <div class="d-flex align-items-center gap-2">
            @if ($this->validation->fails())
                <span class="badge bg-danger-subtle text-danger">
                    {{ $this->validation->count() }} problem(s)
                </span>
            @endif

            <button type="button" class="btn btn-primary" wire:click="commit"
                    @disabled(empty($schema['sections']))>
                Create form
            </button>
        </div>
    </div>
</div>
