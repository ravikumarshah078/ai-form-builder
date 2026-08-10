<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $form->title }}</title>

    {{-- A form under test should not end up in search results. --}}
    <meta name="robots" content="noindex, nofollow">

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="bg-body-tertiary">

{{--
    The public renderer.

    Everything below is driven by $schema — the exact snapshot that was live
    when this page was requested. Nothing reads the Form's current state, which
    is what guarantees the respondent and the stored submission agree on what
    the form looked like.

    Browser-side attributes (required, maxlength, accept) are conveniences
    only. The server recompiles every rule from this same version on POST.
--}}
<div class="container py-5" style="max-width: 720px;">

    <div class="card shadow-sm">
        <div class="card-body p-4 p-md-5">

            <h1 class="h3 mb-1">{{ $form->title }}</h1>

            @if ($form->description)
                <p class="text-body-secondary">{{ $form->description }}</p>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger">
                    <strong>Please correct the following:</strong>
                    <ul class="mb-0 mt-2">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <hr class="mb-4">

            <form method="POST"
                  action="{{ route('public.form.submit', $form->slug) }}"
                  enctype="multipart/form-data"
                  novalidate>
                @csrf

                {{--
                    Honeypot. Hidden from people via CSS rather than
                    type="hidden", because many bots skip hidden inputs but
                    happily fill a visible-in-the-DOM text field.
                --}}
                <div style="position:absolute;left:-9999px;" aria-hidden="true">
                    <label for="_hp">Leave this field empty</label>
                    <input type="text" id="_hp" name="_hp" tabindex="-1" autocomplete="off">
                </div>

                @forelse ($schema->sections() as $sectionIndex => $section)

                    <fieldset class="fb-step mb-4"
                              data-step="{{ $sectionIndex }}"
                              @if ($schema->isMultiStep() && $sectionIndex > 0) hidden @endif>

                        @if (! empty($section['title']))
                            <legend class="h5 mt-3 mb-1">{{ $section['title'] }}</legend>
                        @endif

                        @if (! empty($section['description']))
                            <p class="text-body-secondary small">{{ $section['description'] }}</p>
                        @endif

                        @foreach ($section['fields'] ?? [] as $field)
                            @include('public.partials.field', ['field' => $field])
                        @endforeach
                    </fieldset>

                @empty
                    <div class="alert alert-warning">This form does not have any fields yet.</div>
                @endforelse

                @if ($schema->answerFields() !== [])
                    @if ($schema->isMultiStep() && $schema->sectionCount() > 1)
                        <div class="d-flex justify-content-between align-items-center border-top pt-3">
                            <button type="button" class="btn btn-outline-secondary" data-step-prev hidden>← Back</button>
                            <span class="small text-body-secondary" data-step-counter></span>
                            <button type="button" class="btn btn-primary" data-step-next>Next →</button>
                            <button type="submit" class="btn btn-primary" data-step-submit hidden>
                                {{ $schema->setting('submit_label') }}
                            </button>
                        </div>
                    @else
                        <button type="submit" class="btn btn-primary btn-lg w-100 mt-3">
                            {{ $schema->setting('submit_label') }}
                        </button>
                    @endif
                @endif
            </form>

        </div>
    </div>

    <p class="text-center text-body-secondary small mt-3">
        Version {{ $version->version_number }}
    </p>
</div>

@if ($schema->isMultiStep() && $schema->sectionCount() > 1)
<script>
/**
 * Multi-step navigation.
 *
 * Purely presentational: every step's inputs stay in the DOM, so the whole
 * form posts in one request and the server validates all of it at once. The
 * alternative — posting each step — would need partial submissions and gives
 * a respondent no way to go back and change an answer.
 */
(function () {
    const steps = Array.from(document.querySelectorAll('.fb-step'));
    const prev = document.querySelector('[data-step-prev]');
    const next = document.querySelector('[data-step-next]');
    const submit = document.querySelector('[data-step-submit]');
    const counter = document.querySelector('[data-step-counter]');
    let current = 0;

    const render = () => {
        steps.forEach((s, i) => s.hidden = i !== current);
        prev.hidden = current === 0;
        next.hidden = current === steps.length - 1;
        submit.hidden = current !== steps.length - 1;
        counter.textContent = `Step ${current + 1} of ${steps.length}`;
    };

    next.addEventListener('click', () => {
        // Let the browser flag obvious gaps before moving on. This is a
        // courtesy, not a gate — the server re-checks everything.
        const invalid = steps[current].querySelector(':invalid');
        if (invalid) { invalid.reportValidity(); return; }
        if (current < steps.length - 1) { current++; render(); }
    });

    prev.addEventListener('click', () => { if (current > 0) { current--; render(); } });

    render();
})();
</script>
@endif

</body>
</html>
