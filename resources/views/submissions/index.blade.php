@extends('layouts.app')

@section('title', 'Responses')
@section('heading', $form->title.' — responses')

@section('actions')
    <div class="d-flex gap-2">
        <a href="{{ route('forms.submissions.export', array_filter(['form' => $form->slug, 'q' => $search])) }}"
           class="btn btn-outline-primary text-nowrap">
            Export CSV
        </a>
        <a href="{{ route('forms.build', $form) }}" class="btn btn-outline-secondary text-nowrap">Back to builder</a>
    </div>
@endsection

@section('content')

    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('forms.submissions', $form) }}">
                <div class="row g-3">
                    <div class="col-md-9 col-sm-8 col-12">
                        <label for="q" class="form-label mb-1 fw-semibold text-secondary small">Search responses</label>
                        <div class="input-group">
                            <input type="search" id="q" name="q" value="{{ $search }}" class="form-control"
                                   placeholder="Any answer text — name, email, skill…">
                            <button type="submit" class="btn btn-primary px-4">Search</button>
                        </div>
                        <div class="form-text small mt-1 text-muted">
                            Runs against a FULLTEXT index, so partial words match as you'd expect.
                        </div>
                    </div>
                    @if ($search !== '')
                        <div class="col-md-3 col-sm-4 col-12 d-flex align-items-end">
                            <a href="{{ route('forms.submissions', $form) }}" class="btn btn-outline-secondary w-100 mb-md-3">Reset Search</a>
                        </div>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="badge bg-secondary-subtle text-secondary">
            {{ number_format($submissions->total()) }} response(s)
        </span>
        @if (count($schema->answerLabels()) > count($columns))
            <span class="badge bg-light text-body-secondary">
                showing {{ count($columns) }} of {{ count($schema->answerLabels()) }} columns — open a row for all
            </span>
        @endif
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Submitted</th>
                        @foreach ($columns as $key => $label)
                            <th>{{ $label }}</th>
                        @endforeach
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($submissions as $submission)
                    <tr>
                        <td class="text-nowrap">
                            <div>{{ $submission->submitted_at?->format('d M Y, H:i') }}</div>
                            <small class="text-body-secondary">
                                v{{ $submission->version?->version_number ?? '?' }}
                            </small>
                        </td>

                        @foreach ($columns as $key => $label)
                            <td>
                                {{ Str::limit($schema->displayAnswer($key, $submission->data[$key] ?? null), 40) }}
                            </td>
                        @endforeach

                        <td class="text-end text-nowrap">
                            <a href="{{ route('forms.submissions.show', [$form, $submission]) }}"
                               class="btn btn-sm btn-outline-primary">View</a>

                            <form method="POST"
                                  action="{{ route('forms.submissions.destroy', [$form, $submission]) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete this response permanently? Any uploaded files go too.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + 2 }}" class="text-center py-5 text-body-secondary">
                            @if ($search !== '')
                                No responses match “{{ $search }}”.
                            @elseif ($form->isPublished())
                                No responses yet.
                                <a href="{{ $form->publicUrl() }}" target="_blank" rel="noopener">Open the form</a>
                                to try it.
                            @else
                                This form is not published yet, so it cannot receive responses.
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($submissions->hasPages())
        <div class="mt-3">{{ $submissions->links() }}</div>
    @endif

@endsection
