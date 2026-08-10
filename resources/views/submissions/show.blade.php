@extends('layouts.app')

@section('title', 'Response')
@section('heading', 'Response')

@section('actions')
    <a href="{{ route('forms.submissions', $form) }}" class="btn btn-outline-secondary">Back to responses</a>
@endsection

@section('content')

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">

                {{--
                    Rendered against $submission->version, NOT the form's
                    current version. If a field was renamed or deleted since,
                    this still shows the labels the respondent actually saw —
                    which is the entire reason form_versions is append-only.
                --}}
                @foreach ($schema->sections() as $section)
                    @if (! empty($section['title']))
                        <h6 class="text-uppercase small text-body-secondary mt-4 mb-2">
                            {{ $section['title'] }}
                        </h6>
                    @endif

                    @foreach ($section['fields'] ?? [] as $field)
                        @php
                            $type = \App\Enums\FieldType::tryFrom($field['type'] ?? '');
                        @endphp

                        @continue (! $type?->collectsAnswer() || empty($field['key']))

                        @php
                            $key = $field['key'];
                            $answer = $submission->data[$key] ?? null;
                            $file = $submission->files->firstWhere('field_key', $key);
                        @endphp

                        <div class="row py-2 border-bottom">
                            <div class="col-sm-5 text-body-secondary">
                                {{ $field['label'] }}
                                @if (! empty($field['required']))
                                    <span class="text-danger">*</span>
                                @endif
                            </div>
                            <div class="col-sm-7">
                                @if ($file)
                                    <a href="{{ route('forms.submissions.file', [$form, $submission, $file]) }}">
                                        {{ $file->original_name }}
                                    </a>
                                    <small class="text-body-secondary">({{ $file->humanSize() }})</small>
                                @elseif ($answer === null || $answer === '' || $answer === [])
                                    <span class="text-body-secondary fst-italic">Not answered</span>
                                @else
                                    {{ $schema->displayAnswer($key, $answer) }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endforeach

            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h6 class="mb-3">Metadata</h6>

                <dl class="row small mb-0">
                    <dt class="col-5 text-body-secondary">Reference</dt>
                    <dd class="col-7"><code class="small">{{ $submission->uuid }}</code></dd>

                    <dt class="col-5 text-body-secondary">Submitted</dt>
                    <dd class="col-7">{{ $submission->submitted_at?->format('d M Y, H:i:s') }}</dd>

                    <dt class="col-5 text-body-secondary">Schema version</dt>
                    <dd class="col-7">
                        v{{ $submission->version?->version_number }}
                        @if ($submission->form_version_id !== $form->current_version_id)
                            <span class="badge bg-warning-subtle text-warning-emphasis">superseded</span>
                        @endif
                    </dd>

                    <dt class="col-5 text-body-secondary">Status</dt>
                    <dd class="col-7">{{ ucfirst($submission->status) }}</dd>

                    @foreach ((array) $submission->meta as $key => $value)
                        <dt class="col-5 text-body-secondary">{{ Str::headline($key) }}</dt>
                        <dd class="col-7 text-break">{{ Str::limit((string) $value, 120) }}</dd>
                    @endforeach
                </dl>
            </div>
        </div>

        @if ($submission->form_version_id !== $form->current_version_id)
            <div class="alert alert-info small mt-3">
                This response was captured against an older version of the form.
                It is shown exactly as the respondent saw it.
            </div>
        @endif
    </div>
</div>

@endsection
