<?php

namespace App\Http\Controllers;

use App\Forms\FormSchema;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormSubmissionFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    /**
     * Paginated, searchable list of one form's responses.
     *
     * Two things worth noting:
     *
     * - The query is shaped to hit submissions_listing_idx (form_id,
     *   submitted_at), so filtering and sorting come from one index.
     * - Search runs against the FULLTEXT index on `search_text`, not a LIKE
     *   over the JSON column, which would be a full table scan.
     */
    public function index(Request $request, Form $form): View
    {
        $this->authoriseOwner($request, $form);

        $search = $request->string('q')->toString();

        $submissions = $form->submissions()
            ->search($search)
            ->newestFirst()
            ->paginate(25)
            ->withQueryString();

        // Columns come from the form's CURRENT version, so the table has
        // stable headers. Individual rows may have been captured against an
        // older version and simply have nothing under a newer column.
        $schema = FormSchema::wrap($form->schema());

        return view('submissions.index', [
            'form' => $form,
            'submissions' => $submissions,
            'schema' => $schema,
            'columns' => array_slice($schema->answerLabels(), 0, 6, true),
            'search' => $search,
        ]);
    }

    /**
     * One submission, rendered against the schema it was captured with.
     */
    public function show(Request $request, Form $form, FormSubmission $submission): View
    {
        $this->authoriseOwner($request, $form);
        abort_unless($submission->form_id === $form->id, 404);

        $submission->load('files', 'version');

        return view('submissions.show', [
            'form' => $form,
            'submission' => $submission,
            // The version this was answered against — NOT the current one.
            // This is the whole reason versions are immutable.
            'schema' => FormSchema::wrap($submission->version->schema),
        ]);
    }

    /**
     * Stream every response as CSV.
     *
     * Streamed with a cursor rather than built in memory: a form with 50k
     * responses would otherwise need the whole result set and the whole CSV
     * string resident at once. This holds one row at a time.
     */
    public function export(Request $request, Form $form): StreamedResponse
    {
        $this->authoriseOwner($request, $form);

        $schema = FormSchema::wrap($form->schema());
        $labels = $schema->answerLabels();
        $search = $request->string('q')->toString();

        $filename = $form->slug.'-responses-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($form, $schema, $labels, $search) {
            $out = fopen('php://output', 'w');

            // BOM so Excel opens UTF-8 correctly. Without it, accented
            // characters and most non-Latin scripts render as mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, array_merge(
                ['Reference', 'Submitted at', 'Version'],
                array_values($labels)
            ));

            $form->submissions()
                ->search($search)
                ->newestFirst()
                ->with('version')
                ->chunk(500, function ($chunk) use ($out, $schema, $labels) {
                    foreach ($chunk as $submission) {
                        $row = [
                            $submission->uuid,
                            $submission->submitted_at?->toDateTimeString(),
                            $submission->version?->version_number,
                        ];

                        foreach (array_keys($labels) as $key) {
                            // displayAnswer maps option values back to labels,
                            // so the CSV reads "PHP, SQL" rather than "php,sql".
                            $row[] = $schema->displayAnswer($key, $submission->data[$key] ?? null);
                        }

                        fputcsv($out, $row);
                    }

                    flush();
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Download one uploaded file.
     *
     * Served through the app rather than from a public directory. Uploads are
     * stored on the private `local` disk, so the only way to reach one is via
     * this route, which checks form ownership first. A publicly-served path
     * would leak every attachment to anyone who guessed a filename.
     */
    public function file(Request $request, Form $form, FormSubmission $submission, FormSubmissionFile $file)
    {
        $this->authoriseOwner($request, $form);

        abort_unless($submission->form_id === $form->id, 404);
        abort_unless($file->form_submission_id === $submission->id, 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    public function destroy(Request $request, Form $form, FormSubmission $submission): RedirectResponse
    {
        $this->authoriseOwner($request, $form);
        abort_unless($submission->form_id === $form->id, 404);

        // Hard delete: the files relation cascades and each file's `deleted`
        // hook removes it from disk, so no orphaned uploads are left behind.
        $submission->delete();

        return redirect()
            ->route('forms.submissions', $form)
            ->with('success', 'Response deleted.');
    }

    private function authoriseOwner(Request $request, Form $form): void
    {
        abort_unless($form->user_id === $request->user()->id, 403);
    }
}
