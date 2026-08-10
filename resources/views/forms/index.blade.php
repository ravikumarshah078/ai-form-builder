@extends('layouts.app')

@section('title', 'Forms')
@section('heading', 'Forms')

@section('actions')
    <a href="{{ route('forms.create') }}" class="btn btn-primary">
        <i class="fe fe-plus me-1"></i> New form
    </a>
@endsection

@section('content')

    {{-- Filters --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('forms.index') }}" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label for="q" class="form-label mb-1">Search</label>
                    <input type="search" id="q" name="q" value="{{ request('q') }}"
                           class="form-control" placeholder="Form title&hellip;">
                </div>

                <div class="col-md-3">
                    <label for="status" class="form-label mb-1">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach (\App\Enums\FormStatus::cases() as $case)
                            <option value="{{ $case->value }}" @selected($status === $case->value)>
                                {{ $case->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
                </div>

                @if (request()->hasAny(['q', 'status']))
                    <div class="col-md-2">
                        <a href="{{ route('forms.index') }}" class="btn btn-outline-secondary w-100">Reset</a>
                    </div>
                @endif
            </form>
        </div>
    </div>

    {{-- List --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th class="text-end">Responses</th>
                        <th>Version</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>

                <tbody>
                @forelse ($forms as $form)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $form->title }}</div>
                            @if ($form->isPublished())
                                <a href="{{ $form->publicUrl() }}" target="_blank" rel="noopener"
                                   class="small text-body-secondary">/f/{{ $form->slug }}</a>
                            @else
                                <span class="small text-body-secondary">/f/{{ $form->slug }}</span>
                            @endif
                        </td>

                        <td>
                            <span class="badge {{ $form->status->badgeClass() }}">
                                {{ $form->status->label() }}
                            </span>
                        </td>

                        {{-- Denormalised counter, not a COUNT(*) per row. --}}
                        <td class="text-end">
                            <a href="{{ route('forms.submissions', $form) }}">
                                {{ number_format($form->submission_count) }}
                            </a>
                        </td>

                        <td class="text-body-secondary">
                            v{{ $form->currentVersion?->version_number ?? 1 }}
                        </td>

                        <td class="text-body-secondary">
                            {{ $form->created_at->diffForHumans() }}
                        </td>

                        <td class="text-end">
                            <a href="{{ route('forms.build', $form) }}"
                               class="btn btn-sm btn-outline-primary">Build</a>

                            <a href="{{ route('forms.settings', $form) }}"
                               class="btn btn-sm btn-outline-secondary">Settings</a>

                            <form method="POST" action="{{ route('forms.destroy', $form) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete &quot;{{ $form->title }}&quot;? Responses are kept.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <p class="text-body-secondary mb-3">
                                @if (request()->hasAny(['q', 'status']))
                                    No forms match those filters.
                                @else
                                    You have not created a form yet.
                                @endif
                            </p>
                            <a href="{{ route('forms.create') }}" class="btn btn-primary">
                                Create your first form
                            </a>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($forms->hasPages())
        <div class="mt-3">{{ $forms->links() }}</div>
    @endif

@endsection
