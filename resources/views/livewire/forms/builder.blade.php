@php use App\Enums\FieldType; @endphp

{{--
    Step 2 of the wizard: the canvas.

    Left  = the form being built, one sortable list per section.
    Right = a three-tab panel: Add fields / Field options / JSON.

    All three read the same $schema array on the server, which is why the JSON
    tab and the canvas are always in agreement without any sync mechanism.
--}}
<div>
    @section('title', 'Build form')
    @section('heading', 'Form Builder')

    {{-- Stepper --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                @foreach (['Details', 'Builder', 'Settings', 'Finish'] as $i => $label)
                    @php $n = $i + 1; $active = 2; @endphp
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

    {{-- Status bar --}}
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span class="badge bg-secondary-subtle text-secondary">
            {{ $this->fieldCount }} {{ Str::plural('field', $this->fieldCount) }}
        </span>

        @if ($this->validation->fails())
            <span class="badge bg-danger-subtle text-danger">
                {{ $this->validation->count() }} validation
                {{ Str::plural('problem', $this->validation->count()) }}
            </span>
        @else
            <span class="badge bg-success-subtle text-success">Schema valid</span>
        @endif

        @if ($dirty)
            <span class="badge bg-warning-subtle text-warning-emphasis">Unsaved changes</span>
        @endif

        <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    wire:click="save(false)" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </div>

    <div class="row g-3">

        {{-- ════════ CANVAS ════════ --}}
        <div class="col-lg-8">

            @forelse ($schema['sections'] ?? [] as $si => $section)
                <div class="card mb-3" wire:key="section-{{ $section['id'] }}">
                    <div class="card-header d-flex align-items-center gap-2 py-2">
                        <input type="text"
                               class="form-control form-control-sm border-0 fw-semibold bg-transparent"
                               placeholder="Untitled section"
                               wire:model.live.debounce.400ms="schema.sections.{{ $si }}.title">

                        <div class="btn-group btn-group-sm flex-shrink-0">
                            @if ($si > 0)
                                <button type="button" class="btn btn-outline-secondary"
                                        title="Move section up"
                                        wire:click="moveSection({{ $si }}, {{ $si - 1 }})">↑</button>
                            @endif
                            @if ($si < count($schema['sections']) - 1)
                                <button type="button" class="btn btn-outline-secondary"
                                        title="Move section down"
                                        wire:click="moveSection({{ $si }}, {{ $si + 1 }})">↓</button>
                            @endif
                            <button type="button" class="btn btn-outline-danger"
                                    title="Delete section"
                                    wire:click="deleteSection({{ $si }})"
                                    wire:confirm="Delete this section and all its fields?">✕</button>
                        </div>
                    </div>

                    <div class="card-body">
                        {{--
                            data-sortable-section carries the section index so
                            the drop handler knows the destination without a
                            lookup.
                        --}}
                        <div class="fb-canvas {{ empty($section['fields']) ? 'is-empty' : '' }}"
                             data-sortable-section="{{ $si }}">

                            @forelse ($section['fields'] ?? [] as $fi => $field)
                                @include('livewire.forms.partials.canvas-field', [
                                    'field' => $field,
                                    'si' => $si,
                                    'fi' => $fi,
                                ])
                            @empty
                                <div class="text-center py-4">
                                    <p class="mb-1">Drag a field here</p>
                                    <small>or click one in the palette</small>
                                </div>
                            @endforelse

                        </div>
                    </div>
                </div>
            @empty
                <div class="card">
                    <div class="card-body text-center py-5">
                        <p class="text-body-secondary mb-3">This form has no sections yet.</p>
                        <button type="button" class="btn btn-primary" wire:click="addSection">
                            Add the first section
                        </button>
                    </div>
                </div>
            @endforelse

            @if (! empty($schema['sections']))
                <button type="button" class="btn btn-outline-primary btn-sm" wire:click="addSection">
                    + Add section
                </button>
            @endif
        </div>

        {{-- ════════ RIGHT PANEL ════════ --}}
        <div class="col-lg-4">
            <div class="card position-sticky" style="top: 1rem;">

                <div class="card-header p-0">
                    <ul class="nav nav-tabs card-header-tabs m-0">
                        @foreach (['add' => 'Add fields', 'options' => 'Options', 'json' => 'JSON', 'ai' => '✨ AI'] as $key => $label)
                            <li class="nav-item">
                                <button type="button"
                                        class="nav-link {{ $tab === $key ? 'active' : '' }}"
                                        wire:click="setTab('{{ $key }}')">
                                    {{ $label }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="card-body" style="max-height: 70vh; overflow-y: auto;">
                    @if ($tab === 'add')
                        @include('livewire.forms.partials.palette')
                    @elseif ($tab === 'options')
                        @include('livewire.forms.partials.field-options')
                    @elseif ($tab === 'ai')
                        @include('livewire.forms.partials.ai-panel')
                    @else
                        @include('livewire.forms.partials.json-editor')
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Wizard navigation --}}
    <div class="d-flex justify-content-between mt-4">
        <a href="{{ route('forms.details', $form) }}" class="btn btn-outline-secondary" wire:navigate>
            ← Back: Details
        </a>

        <button type="button" class="btn btn-primary" wire:click="save">
            Save &amp; continue →
        </button>
    </div>
</div>

@script
<script>
    /**
     * Wire SortableJS to each section's field list.
     *
     * The important detail is the revert in onEnd. Sortable mutates the DOM
     * immediately, but the server's $schema array is the authority on order.
     * If we left Sortable's change in place, Livewire's re-render would apply
     * the move a second time and the field would jump two positions. So we put
     * the element back where it started and let the server's response perform
     * the actual move.
     */
    function initSortables() {
        document.querySelectorAll('[data-sortable-section]').forEach((el) => {
            if (el._sortable) {
                el._sortable.destroy();
            }

            el._sortable = new Sortable(el, {
                group: 'builder-fields',
                handle: '.fb-field__handle',
                animation: 150,
                ghostClass: 'fb-ghost',
                dragClass: 'fb-drag',

                onEnd(evt) {
                    var fieldId = evt.item.dataset.fieldId;
                    var toSection = parseInt(evt.to.dataset.sortableSection, 10);
                    var toIndex = evt.newIndex;

                    // Put the DOM back exactly as it was.
                    var siblings = evt.from.children;
                    if (evt.oldIndex < siblings.length) {
                        evt.from.insertBefore(evt.item, siblings[evt.oldIndex]);
                    } else {
                        evt.from.appendChild(evt.item);
                    }

                    if (fieldId && !Number.isNaN(toSection)) {
                        $wire.moveField(fieldId, toSection, toIndex);
                    }
                },
            });
        });
    }

    initSortables();

    // Livewire replaces the canvas markup on every state change, which throws
    // away Sortable's listeners. Re-bind after each patch.
    Livewire.hook('morphed', ({ component }) => {
        if (component.id === $wire.id) {
            initSortables();
        }
    });
</script>
@endscript
