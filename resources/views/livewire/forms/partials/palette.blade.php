@php
    // Simple glyphs rather than an icon font: the vendor theme's icon set does
    // not cover every field type, and a missing glyph renders as an empty box
    // with no clue what the button does.
    $icons = [
        'text' => 'Ab', 'textarea' => '¶', 'email' => '@', 'phone' => '☎',
        'url' => '🔗', 'number' => '#', 'date' => '📅', 'time' => '🕐',
        'datetime' => '📆', 'dropdown' => '▼', 'radio' => '◉', 'checkbox' => '☑',
        'rating' => '★', 'file' => '📎', 'heading' => 'H', 'paragraph' => '¶',
        'divider' => '—',
    ];
@endphp

{{--
    The "Add fields" palette.

    Click-to-add only. The brief requires both interactions, and they are
    genuinely different gestures: dragging FROM here would need a second
    Sortable group and a clone strategy, whereas clicking appends to the last
    section, which is what someone building quickly actually wants. Dragging
    is what reorders fields once they exist.
--}}
<div>
    @foreach ($this->palette as $group => $types)
        <div class="mb-3">
            <div class="text-uppercase small fw-semibold text-body-secondary mb-2">
                {{ $group }} fields
            </div>

            <div class="row g-2">
                @foreach ($types as $type)
                    <div class="col-4">
                        <button type="button"
                                class="fb-palette__item w-100 h-100"
                                wire:click="addField('{{ $type->value }}')"
                                title="Add a {{ $type->label() }} field">
                            <span aria-hidden="true">{{ $icons[$type->value] ?? '▢' }}</span>
                            <span>{{ $type->label() }}</span>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="alert alert-light border small mb-0">
        Click a field to append it to the last section, then drag the
        <span class="fw-semibold">⠿</span> handle to reorder.
    </div>
</div>
