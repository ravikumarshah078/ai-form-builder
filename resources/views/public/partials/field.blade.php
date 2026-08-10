@php
    use App\Enums\FieldType;

    // An unknown type renders as plain text rather than crashing the page. The
    // schema validator should have caught it long before publication, so this
    // is a last line of defence, not the expected path.
    $type  = FieldType::tryFrom($field['type'] ?? '') ?? FieldType::Text;
    $key   = $field['key'] ?? ($field['id'] ?? 'field');
    $id    = 'f_'.$key;
    $req   = ! empty($field['required']);
    $help  = $field['help'] ?? null;
    $ph    = $field['placeholder'] ?? '';
    $opts  = $field['options'] ?? [];
    $rules = $field['validation'] ?? [];

    // Repopulate after a failed submit so nobody retypes a long form.
    $old   = old($key, $field['default'] ?? null);
    $bad   = $errors->has($key) || $errors->has($key.'.*');
    $msg   = $errors->first($key) ?: $errors->first($key.'.*');
@endphp

@switch (true)

    {{-- ── Presentational: collect no answer ──────────────────────────── --}}
    @case ($type === FieldType::Heading)
        <h3 class="h5 mt-4 mb-2">{{ $field['label'] ?? '' }}</h3>
        @break

    @case ($type === FieldType::Paragraph)
        <p class="text-body-secondary">{{ $field['label'] ?? '' }}</p>
        @break

    @case ($type === FieldType::Divider)
        <hr class="my-4">
        @break

    {{-- ── Dropdown ──────────────────────────────────────────────────── --}}
    @case ($type === FieldType::Dropdown)
        <div class="mb-3">
            <label for="{{ $id }}" class="form-label">
                {{ $field['label'] ?? '' }} @if ($req)<span class="text-danger">*</span>@endif
            </label>
            <select id="{{ $id }}" name="{{ $key }}"
                    class="form-select @if($bad) is-invalid @endif" @required($req)>
                <option value="">{{ $ph ?: 'Choose…' }}</option>
                @foreach ($opts as $opt)
                    <option value="{{ $opt['value'] }}" @selected((string) $old === (string) $opt['value'])>
                        {{ $opt['label'] }}
                    </option>
                @endforeach
            </select>
            @if ($msg)<div class="invalid-feedback d-block">{{ $msg }}</div>@endif
            @if ($help)<div class="form-text">{{ $help }}</div>@endif
        </div>
        @break

    {{-- ── Radio / checkbox groups ───────────────────────────────────── --}}
    @case ($type === FieldType::Radio || $type === FieldType::Checkbox)
        @php
            $isCheckbox = $type === FieldType::Checkbox;
            // A checkbox group posts an array; a radio posts a scalar.
            $name = $isCheckbox ? $key.'[]' : $key;
            $selected = $isCheckbox ? (array) old($key, []) : [(string) $old];
        @endphp
        <fieldset class="mb-3">
            <legend class="form-label fs-6">
                {{ $field['label'] ?? '' }} @if ($req)<span class="text-danger">*</span>@endif
            </legend>
            @foreach ($opts as $i => $opt)
                <div class="form-check">
                    <input type="{{ $isCheckbox ? 'checkbox' : 'radio' }}"
                           class="form-check-input @if($bad) is-invalid @endif"
                           id="{{ $id }}_{{ $i }}"
                           name="{{ $name }}"
                           value="{{ $opt['value'] }}"
                           @checked(in_array((string) $opt['value'], array_map('strval', $selected), true))
                           @if ($req && ! $isCheckbox) required @endif>
                    <label class="form-check-label" for="{{ $id }}_{{ $i }}">{{ $opt['label'] }}</label>
                </div>
            @endforeach
            @if ($msg)<div class="invalid-feedback d-block">{{ $msg }}</div>@endif
            @if ($help)<div class="form-text">{{ $help }}</div>@endif
        </fieldset>
        @break

    {{-- ── Rating ────────────────────────────────────────────────────── --}}
    @case ($type === FieldType::Rating)
        <div class="mb-3">
            <label for="{{ $id }}" class="form-label">
                {{ $field['label'] ?? '' }} @if ($req)<span class="text-danger">*</span>@endif
            </label>
            <input type="number" id="{{ $id }}" name="{{ $key }}"
                   class="form-control @if($bad) is-invalid @endif"
                   min="{{ $rules['min'] ?? 1 }}" max="{{ $rules['max'] ?? 5 }}" step="1"
                   value="{{ $old }}" @required($req)>
            @if ($msg)<div class="invalid-feedback d-block">{{ $msg }}</div>@endif
            @if ($help)<div class="form-text">{{ $help }}</div>@endif
        </div>
        @break

    {{-- ── Textarea ──────────────────────────────────────────────────── --}}
    @case ($type === FieldType::Textarea)
        <div class="mb-3">
            <label for="{{ $id }}" class="form-label">
                {{ $field['label'] ?? '' }} @if ($req)<span class="text-danger">*</span>@endif
            </label>
            <textarea id="{{ $id }}" name="{{ $key }}" rows="4"
                      class="form-control @if($bad) is-invalid @endif"
                      placeholder="{{ $ph }}"
                      @if (! empty($rules['max_length'])) maxlength="{{ $rules['max_length'] }}" @endif
                      @required($req)>{{ $old }}</textarea>
            @if ($msg)<div class="invalid-feedback d-block">{{ $msg }}</div>@endif
            @if ($help)<div class="form-text">{{ $help }}</div>@endif
        </div>
        @break

    {{-- ── File ──────────────────────────────────────────────────────── --}}
    @case ($type === FieldType::File)
        @php $mimes = $rules['mimes'] ?? []; @endphp
        <div class="mb-3">
            <label for="{{ $id }}" class="form-label">
                {{ $field['label'] ?? '' }} @if ($req)<span class="text-danger">*</span>@endif
            </label>
            <input type="file" id="{{ $id }}" name="{{ $key }}"
                   class="form-control @if($bad) is-invalid @endif"
                   @if ($mimes) accept="{{ collect($mimes)->map(fn ($m) => '.'.$m)->implode(',') }}" @endif
                   @required($req)>
            @if ($msg)<div class="invalid-feedback d-block">{{ $msg }}</div>@endif
            <div class="form-text">
                @if ($help){{ $help }} @endif
                @if ($mimes)
                    Accepted: {{ implode(', ', $mimes) }}.
                @endif
                @if (! empty($rules['max_kb']))
                    Max {{ round($rules['max_kb'] / 1024, 1) }} MB.
                @endif
            </div>
        </div>
        @break

    {{-- ── Everything with a plain <input> ───────────────────────────── --}}
    @default
        @php
            // Map the domain type onto an HTML input type. A browser hint only;
            // the server re-validates from the same schema.
            $html = match ($type) {
                FieldType::Email    => 'email',
                FieldType::Phone    => 'tel',
                FieldType::Url      => 'url',
                FieldType::Number   => 'number',
                FieldType::Date     => 'date',
                FieldType::Time     => 'time',
                FieldType::DateTime => 'datetime-local',
                default             => 'text',
            };
        @endphp
        <div class="mb-3">
            <label for="{{ $id }}" class="form-label">
                {{ $field['label'] ?? '' }} @if ($req)<span class="text-danger">*</span>@endif
            </label>
            <input type="{{ $html }}" id="{{ $id }}" name="{{ $key }}"
                   class="form-control @if($bad) is-invalid @endif"
                   placeholder="{{ $ph }}"
                   value="{{ $old }}"
                   @if (! empty($rules['max_length'])) maxlength="{{ $rules['max_length'] }}" @endif
                   @if (isset($rules['min']) && $rules['min'] !== null) min="{{ $rules['min'] }}" @endif
                   @if (isset($rules['max']) && $rules['max'] !== null) max="{{ $rules['max'] }}" @endif
                   @required($req)>
            @if ($msg)<div class="invalid-feedback d-block">{{ $msg }}</div>@endif
            @if ($help)<div class="form-text">{{ $help }}</div>@endif
        </div>

@endswitch
