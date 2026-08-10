<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Thank you · {{ $form->title }}</title>
    <meta name="robots" content="noindex, nofollow">
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="bg-body-tertiary">

{{--
    Reached by redirect after a successful POST, so a refresh cannot resubmit.
--}}
<div class="container py-5" style="max-width: 560px;">
    <div class="card shadow-sm text-center">
        <div class="card-body p-5">

            <div class="display-4 mb-3" aria-hidden="true">✓</div>

            <h1 class="h4 mb-3">{{ $form->title }}</h1>

            <p class="text-body-secondary mb-4">
                {{ $schema->setting('success_message') }}
            </p>

            @if (session('submission_uuid'))
                <div class="border rounded p-2 bg-body-tertiary">
                    <small class="text-body-secondary d-block">Your reference</small>
                    <code class="small">{{ session('submission_uuid') }}</code>
                </div>
            @endif

        </div>
    </div>
</div>

</body>
</html>
