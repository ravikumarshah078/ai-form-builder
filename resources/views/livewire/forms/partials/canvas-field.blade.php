@php
    use App\Enums\FieldType;

    $type = FieldType::tryFrom($field['type'] ?? '') ?? FieldType::Text;
    $isSelected = $selectedFieldId === ($field['id'] ?? null);
@endphp

{{--
    One field as it appears on the CANVAS — a preview, not the live input.

    Inputs are rendered disabled: clicking a field selects it for editing, and
    a focusable control here would swallow that click and let the builder type
    into a preview that discards what they wrote.
--}}
<div class="fb-field {{ $isSelected ? 'is-selected' : '' }}"
     wire:key="field-{{ $field['id'] }}"
     data-field-id="{{ $field['id'] }}"
     wire:click="selectField('{{ $field['id'] }}')">

    <div class="fb-field__actions">
        <button type="button" class="btn btn-sm btn-light fb-field__handle" title="Drag to reorder">⠿</button>

        <button type="button" class="btn btn-sm btn-light" title="Duplicate"
                wire:click.stop="duplicateField('{{ $field['id'] }}')">⧉</button>

        <button type="button" class="btn btn-sm btn-light text-danger" title="Delete"
                wire:click.stop="deleteField('{{ $field['id'] }}')">🗑</button>
    </div>

    @switch (true)

        @case ($type === FieldType::Heading)
            <h5 class="mb-0 pe-5">{{ $field['label'] ?: 'Untitled heading' }}</h5>
            @break

        @case ($type === FieldType::Paragraph)
            <p class="text-body-secondary mb-0 pe-5">{{ $field['label'] ?: 'Description text' }}</p>
            @break

        @case ($type === FieldType::Divider)
            <div class="pe-5"><hr class="my-1"></div>
            @break

        @default
            <label class="form-label small fw-semibold mb-1 pe-5">
                {{ $field['label'] ?: 'Untitled field' }}
                @if (! empty($field['required']))
                    <span class="text-danger">*</span>
                @endif
                <span class="badge bg-light text-body-secondary fw-normal ms-1">{{ $type->label() }}</span>
            </label>

            @if ($type === FieldType::Textarea)
                <textarea class="form-control form-control-sm" rows="2" disabled
                          placeholder="{{ $field['placeholder'] }}"></textarea>

            @elseif ($type->hasOptions())
                @if ($type === FieldType::Dropdown)
                    <select class="form-select form-select-sm" disabled>
                        <option>{{ $field['placeholder'] ?: 'Choose…' }}</option>
                    </select>
                @else
                    @foreach (array_slice($field['options'] ?? [], 0, 4) as $i => $opt)
                        <div class="form-check">
                            <input class="form-check-input"
                                   type="{{ $type === FieldType::Checkbox ? 'checkbox' : 'radio' }}" disabled>
                            <label class="form-check-label small">{{ $opt['label'] ?? '' }}</label>
                        </div>
                    @endforeach
                    @if (count($field['options'] ?? []) > 4)
                        <small class="text-body-secondary">
                            +{{ count($field['options']) - 4 }} more
                        </small>
                    @endif
                @endif

            @elseif ($type === FieldType::Rating)
                <div class="text-warning">★★★★★</div>

            @else
                <input type="text" class="form-control form-control-sm" disabled
                       placeholder="{{ $field['placeholder'] }}">
            @endif

            @if (! empty($field['help']))
                <div class="form-text small">{{ $field['help'] }}</div>
            @endif
    @endswitch

    @if (! empty($field['key']))
        <div class="mt-1">
            <code class="small text-body-secondary">{{ $field['key'] }}</code>
        </div>
    @endif
</div>
