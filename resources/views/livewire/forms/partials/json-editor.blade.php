{{--
    The raw JSON editor.

    Opening this tab re-serialises from the canvas, so it always shows current
    truth. Pressing Apply parses, normalises and validates; the canvas is only
    replaced if the result is valid, so a half-typed document can never destroy
    the user's work.

    Applying also re-serialises the NORMALISED result back into the textarea,
    which makes any repair visible immediately rather than a surprise later.
--}}
<div>
    <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="small fw-semibold">Schema (source of truth)</span>

        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary"
                    wire:click="setTab('json')" title="Discard edits and reload from the canvas">
                Reload
            </button>
            <button type="button" class="btn btn-primary" wire:click="applyJson">
                Apply to canvas
            </button>
        </div>
    </div>

    @if ($jsonErrors !== [])
        <div class="alert alert-danger py-2 small">
            <div class="fw-semibold mb-1">
                {{ collect($jsonErrors)->flatten()->count() }} problem(s) — canvas not updated:
            </div>
            <ul class="mb-0 ps-3">
                @foreach ($jsonErrors as $path => $messages)
                    @foreach ($messages as $message)
                        <li>
                            @if ($path !== '')
                                <code>{{ $path }}</code> —
                            @endif
                            {{ $message }}
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif

    <textarea class="form-control font-monospace"
              rows="24"
              spellcheck="false"
              style="font-size: 0.75rem; tab-size: 2;"
              wire:model="rawJson"></textarea>

    <div class="form-text small">
        Edits here are not applied until you press <strong>Apply to canvas</strong>.
        Unknown properties are dropped and recognised aliases are repaired on apply.
    </div>
</div>
