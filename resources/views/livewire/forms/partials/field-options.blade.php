@php use App\Enums\FieldType; @endphp

{{--
    The "Field options" panel: per-field configuration.

    Inputs bind to a computed dot-path into $schema, so editing here mutates
    the same array the canvas renders from and the JSON tab serialises. That is
    the whole two-way-sync story — there is no second copy to keep in step.
--}}
@php
    $path = $this->selectedPath;
    $field = $this->selectedField;
    $type = $this->selectedType;
@endphp

@if ($field === null || $path === null)

    <div class="text-center text-body-secondary py-5">
        <p class="mb-1">No field selected.</p>
        <small>Click a field on the canvas to configure it.</small>
    </div>

@else
    @php [$si, $fi] = $path; $base = "schema.sections.{$si}.fields.{$fi}"; @endphp

    <div class="d-flex align-items-center justify-content-between mb-3">
        <span class="badge bg-primary-subtle text-primary">{{ $type->label() }}</span>
        <code class="small text-body-secondary">{{ $field['id'] }}</code>
    </div>

    {{-- Label --}}
    <div class="mb-3">
        <label class="form-label small fw-semibold">
            {{ $type === FieldType::Divider ? 'Label (unused for a divider)' : 'Label' }}
        </label>
        <input type="text" class="form-control form-control-sm"
               wire:model.live.debounce.400ms="{{ $base }}.label">
    </div>

    @if ($type->collectsAnswer())
        {{-- Key --}}
        <div class="mb-3">
            <label class="form-label small fw-semibold">Key</label>
            <input type="text" class="form-control form-control-sm font-monospace"
                   wire:model.live.debounce.500ms="{{ $base }}.key">
            <div class="form-text small">
                @if ($form->submission_count > 0)
                    <span class="text-warning-emphasis">
                        This form has {{ $form->submission_count }} response(s).
                        Changing the key will orphan existing answers.
                    </span>
                @else
                    Used as the CSV column and the JSON key. Auto-derived from
                    the label until you edit it.
                @endif
            </div>
        </div>

        {{-- Placeholder + help --}}
        <div class="mb-3">
            <label class="form-label small fw-semibold">Placeholder</label>
            <input type="text" class="form-control form-control-sm"
                   wire:model.live.debounce.400ms="{{ $base }}.placeholder">
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold">Help text</label>
            <input type="text" class="form-control form-control-sm"
                   wire:model.live.debounce.400ms="{{ $base }}.help">
        </div>

        {{-- Required --}}
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="opt-required"
                   wire:model.live="{{ $base }}.required">
            <label class="form-check-label small fw-semibold" for="opt-required">Required</label>
        </div>
    @endif

    {{-- Options manager --}}
    @if ($type->hasOptions())
        <hr>
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="small fw-semibold">Options</span>
            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addOption">+ Add</button>
        </div>

        @foreach ($field['options'] ?? [] as $oi => $option)
            <div class="input-group input-group-sm mb-2" wire:key="opt-{{ $field['id'] }}-{{ $oi }}">
                <input type="text" class="form-control" placeholder="Label"
                       wire:model.live.debounce.400ms="{{ $base }}.options.{{ $oi }}.label">
                <input type="text" class="form-control font-monospace" placeholder="value"
                       wire:model.live.debounce.400ms="{{ $base }}.options.{{ $oi }}.value">
                <button type="button" class="btn btn-outline-danger"
                        wire:click="removeOption({{ $oi }})">✕</button>
            </div>
        @endforeach

        @if (empty($field['options']))
            <div class="alert alert-warning small py-2">A choice field needs at least one option.</div>
        @endif
    @endif

    {{-- Validation rules, shown per type --}}
    @if ($type->collectsAnswer())
        <hr>
        <div class="small fw-semibold mb-2">Validation</div>

        @if (in_array($type, [FieldType::Text, FieldType::Textarea, FieldType::Email, FieldType::Phone, FieldType::Url], true))
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small">Min length</label>
                    <input type="number" min="0" class="form-control form-control-sm"
                           wire:model.live.debounce.500ms="{{ $base }}.validation.min_length">
                </div>
                <div class="col-6">
                    <label class="form-label small">Max length</label>
                    <input type="number" min="1" class="form-control form-control-sm"
                           wire:model.live.debounce.500ms="{{ $base }}.validation.max_length">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label small">Pattern (regex, no delimiters)</label>
                <input type="text" class="form-control form-control-sm font-monospace"
                       placeholder="^[A-Z]{3}[0-9]{4}$"
                       wire:model.live.debounce.600ms="{{ $base }}.validation.regex">
            </div>
        @endif

        @if (in_array($type, [FieldType::Number, FieldType::Rating], true))
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small">Minimum</label>
                    <input type="number" class="form-control form-control-sm"
                           wire:model.live.debounce.500ms="{{ $base }}.validation.min">
                </div>
                <div class="col-6">
                    <label class="form-label small">Maximum</label>
                    <input type="number" class="form-control form-control-sm"
                           wire:model.live.debounce.500ms="{{ $base }}.validation.max">
                </div>
            </div>
        @endif

        @if ($type === FieldType::File)
            <div class="mb-2">
                <label class="form-label small">Max size (KB)</label>
                <input type="number" min="1" class="form-control form-control-sm"
                       wire:model.live.debounce.500ms="{{ $base }}.validation.max_kb">
                <div class="form-text small">
                    {{ round((int) ($field['validation']['max_kb'] ?? 0) / 1024, 1) }} MB
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label small">Allowed extensions</label>
                <div class="d-flex flex-wrap gap-2">
                    @foreach (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'zip', 'csv', 'txt'] as $ext)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="{{ $ext }}"
                                   id="mime-{{ $ext }}"
                                   wire:model.live="{{ $base }}.validation.mimes">
                            <label class="form-check-label small" for="mime-{{ $ext }}">.{{ $ext }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <hr>
    <button type="button" class="btn btn-sm btn-outline-danger w-100"
            wire:click="deleteField('{{ $field['id'] }}')">
        Delete this field
    </button>
@endif
