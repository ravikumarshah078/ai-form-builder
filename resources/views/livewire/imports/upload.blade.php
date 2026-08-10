{{-- Upload a .docx or .xlsx to turn into a form. --}}
<div @if ($import && ! $import->isFinished() && ! $import->awaitingReview()) wire:poll.1500ms="poll" @endif>
    @section('title', 'Import a document')
    @section('heading', 'Import from Word or Excel')

    <div class="row g-3">
        <div class="col-lg-7">

            @if ($import && in_array($import->status, ['queued', 'parsing'], true))

                <div class="card">
                    <div class="card-body text-center py-5">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Working…</span>
                        </div>
                        <h5 class="mb-1">
                            {{ $import->status === 'queued' ? 'Queued…' : 'Reading your document…' }}
                        </h5>
                        <p class="text-body-secondary mb-0">{{ $import->original_filename }}</p>
                    </div>
                </div>

            @elseif ($import && $import->status === 'failed')

                <div class="card border-danger">
                    <div class="card-body">
                        <h5 class="text-danger mb-2">That document could not be imported</h5>
                        <p class="small mb-3">{{ $import->error }}</p>
                        <button type="button" class="btn btn-outline-primary" wire:click="dismiss">
                            Try another file
                        </button>
                    </div>
                </div>

            @else

                <div class="card">
                    <div class="card-body">
                        <h5 class="mb-1">Upload a document</h5>
                        <p class="text-body-secondary">
                            A Word form or an Excel sheet becomes an editable form. You will get
                            a preview to correct anything detected wrongly before it is created.
                        </p>

                        <form wire:submit="save">
                            <div class="mb-3">
                                <input type="file" accept=".docx,.xlsx"
                                       class="form-control @error('document') is-invalid @enderror"
                                       wire:model="document">
                                @error('document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">.docx or .xlsx, up to 10 MB.</div>
                            </div>

                            <div wire:loading wire:target="document" class="small text-body-secondary mb-2">
                                Uploading…
                            </div>

                            <button type="submit" class="btn btn-primary"
                                    wire:loading.attr="disabled" wire:target="save,document">
                                <span wire:loading.remove wire:target="save">Import</span>
                                <span wire:loading wire:target="save">Starting…</span>
                            </button>
                        </form>
                    </div>
                </div>

            @endif

            @if ($recent->isNotEmpty())
                <div class="card mt-3">
                    <div class="card-body">
                        <h6 class="mb-3">Recent imports</h6>
                        <div class="table-responsive">
                            <table class="table table-sm small mb-0 align-middle">
                                <thead><tr><th>File</th><th>Status</th><th>Fields</th><th></th></tr></thead>
                                <tbody>
                                @foreach ($recent as $row)
                                    <tr>
                                        <td>{{ Str::limit($row->original_filename, 28) }}</td>
                                        <td>
                                            <span class="badge {{ $row->status === 'committed' ? 'bg-success' : ($row->status === 'failed' ? 'bg-danger' : 'bg-secondary') }}">
                                                {{ str_replace('_', ' ', $row->status) }}
                                            </span>
                                        </td>
                                        <td>{{ $row->stats['fields'] ?? '—' }}</td>
                                        <td class="text-end">
                                            @if ($row->awaitingReview())
                                                <a href="{{ route('imports.review', $row) }}"
                                                   class="btn btn-sm btn-outline-primary" wire:navigate>Review</a>
                                            @elseif ($row->form_id)
                                                <a href="{{ route('forms.build', $row->form) }}"
                                                   class="btn btn-sm btn-outline-secondary" wire:navigate>Open</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- What is supported --}}
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-3">What gets detected</h6>

                    <div class="small fw-semibold text-uppercase text-body-secondary mb-1">Word (.docx)</div>
                    <ul class="small ps-3 mb-3">
                        <li>Headings become sections.</li>
                        <li>Questions become fields — lines ending in “?”, numbered lines,
                            and lines ending in a colon.</li>
                        <li>Bullet and checkbox lists become that question’s options.</li>
                        <li>Two-column tables are read as label / answer pairs.</li>
                    </ul>

                    <div class="small fw-semibold text-uppercase text-body-secondary mb-1">Excel (.xlsx)</div>
                    <ul class="small ps-3 mb-3">
                        <li><strong>Definition sheet</strong> — one row per field, with columns
                            named Section, Label, Type, Required, Options, Help. Types are taken
                            as given.</li>
                        <li><strong>Plain data sheet</strong> — row 1 is treated as field names,
                            and row 2 as sample values used to work out each type.</li>
                    </ul>

                    <div class="border-top pt-2 small text-body-secondary">
                        The document is parsed deterministically first. AI is only asked about
                        questions the parser could not classify, and it can never add, remove or
                        rename a field.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
