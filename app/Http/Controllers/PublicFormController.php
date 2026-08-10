<?php

namespace App\Http\Controllers;

use App\Enums\FieldType;
use App\Forms\FormSchema;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormSubmissionFile;
use App\Models\FormVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public fill URL: /f/{slug}
 *
 * Separate from FormController because the trust model is completely
 * different. Nothing here assumes a session, and every rule applied to the
 * incoming payload is compiled from the STORED schema version — never from
 * anything the request supplied.
 */
class PublicFormController extends Controller
{
    public function show(Form $form): View
    {
        [$form, $version] = $this->resolvePublished($form);

        return view('public.form', [
            'form' => $form,
            'version' => $version,
            'schema' => FormSchema::wrap($version->schema),
        ]);
    }

    /**
     * Accept a submission.
     *
     * The order here matters:
     *   1. resolve the published version (404 if not live);
     *   2. compile rules FROM THAT VERSION;
     *   3. validate the request against them;
     *   4. keep only keys the schema declares;
     *   5. store files, then the submission, in one transaction.
     *
     * Step 4 is what stops a crafted payload from writing arbitrary keys into
     * the JSON column. Validation alone would not: Laravel ignores extra keys
     * it has no rule for.
     */
    public function store(Request $request, Form $form): RedirectResponse
    {
        [$form, $version] = $this->resolvePublished($form);

        $schema = FormSchema::wrap($version->schema);

        // Cheap bot filter: a field hidden from humans that only a script fills
        // in. Silently accepted so the bot does not learn it was caught.
        if ($request->filled('_hp')) {
            return $this->successResponse($form, $schema, null);
        }

        $compiled = $schema->rules();

        $validated = Validator::make(
            $request->all(),
            $compiled['rules'],
            $compiled['messages'],
            $compiled['attributes'],
        )->validate();

        [$answers, $uploads] = $this->partition($schema, $validated, $request);

        $submission = DB::transaction(function () use ($form, $version, $answers, $uploads, $request) {
            $submission = FormSubmission::create([
                'form_id' => $form->id,
                'form_version_id' => $version->id,
                'data' => $answers,
                'meta' => [
                    'ip' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                    'referrer' => Str::limit((string) $request->headers->get('referer'), 500, ''),
                ],
                'status' => 'complete',
            ]);

            foreach ($uploads as $key => $file) {
                // Stored under the form's own directory with a generated name.
                // The respondent's filename is recorded but never used as a
                // path, so "../../etc/passwd" is just a string in a column.
                $path = $file->store('submissions/'.$form->uuid, 'local');

                FormSubmissionFile::create([
                    'form_submission_id' => $submission->id,
                    'field_key' => $key,
                    'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
                    'disk' => 'local',
                    'path' => $path,
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);
            }

            return $submission;
        });

        return $this->successResponse($form, $schema, $submission);
    }

    public function success(Form $form): View
    {
        [$form, $version] = $this->resolvePublished($form);

        return view('public.success', [
            'form' => $form,
            'schema' => FormSchema::wrap($version->schema),
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Load the form's live version, or 404.
     *
     * A draft or archived form is a 404, not a 403. A 403 would confirm that
     * something exists at this slug, which a stranger has no business knowing.
     *
     * @return array{0: Form, 1: FormVersion}
     */
    private function resolvePublished(Form $form): array
    {
        abort_unless($form->isPublished(), 404);

        $version = $form->currentVersion;

        abort_if($version === null, 404);

        return [$form, $version];
    }

    /**
     * Split validated input into answers to store and files to move.
     *
     * Only keys the schema declares survive. Uploads are pulled out of the
     * answer array because the file itself goes to disk; what stays in `data`
     * is the original filename, so a submission still reads sensibly on its
     * own.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: array<string, mixed>, 1: array<string, \Illuminate\Http\UploadedFile>}
     */
    private function partition(FormSchema $schema, array $validated, Request $request): array
    {
        $answers = [];
        $uploads = [];

        foreach ($schema->answerFields() as $field) {
            $key = $field['key'];
            $type = FieldType::tryFrom($field['type']);

            if ($type === FieldType::File) {
                if ($request->hasFile($key)) {
                    $file = $request->file($key);
                    $uploads[$key] = $file;
                    $answers[$key] = $file->getClientOriginalName();
                }

                continue;
            }

            if (! array_key_exists($key, $validated)) {
                continue;
            }

            $value = $validated[$key];

            // Drop empties rather than storing nulls, so a CSV export does not
            // fill up with the string "null" and search_text stays clean.
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $answers[$key] = $value;
        }

        return [$answers, $uploads];
    }

    private function successResponse(Form $form, FormSchema $schema, ?FormSubmission $submission): RedirectResponse
    {
        $redirect = $schema->setting('redirect_url');

        // Re-checked here even though the validator enforces it on save: this
        // value ends up in a Location header, and an away-redirect is worth
        // being paranoid about.
        if (is_string($redirect) && preg_match('#^https?://#i', $redirect)) {
            return redirect()->away($redirect);
        }

        return redirect()
            ->route('public.form.success', $form->slug)
            ->with('submission_uuid', $submission?->uuid);
    }
}
