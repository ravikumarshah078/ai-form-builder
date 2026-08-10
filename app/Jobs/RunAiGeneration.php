<?php

namespace App\Jobs;

use App\Enums\FormStatus;
use App\Forms\FieldFactory;
use App\Models\AiGeneration;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\FormGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one AI generation off the web request.
 *
 * The brief is explicit: "Run generation as a queued job with visible status...
 * Don't block a web request on a long LLM call." A generation can take twenty
 * seconds; holding a PHP-FPM worker for that is how a form builder falls over
 * under three concurrent users.
 *
 * Status is visible because AiGeneration IS the status record — the browser
 * polls that row. There is no second job-tracking mechanism to keep in sync.
 *
 * The job stays thin: FormGenerator does the LLM work, and this class decides
 * what to do with the result. That split means the generator can be tested
 * without a queue, and the persistence rules live next to the models they
 * touch.
 */
class RunAiGeneration implements ShouldQueue
{
    use Queueable;

    /**
     * Retries are handled INSIDE FormGenerator, which knows the difference
     * between a transport blip worth retrying and a model mistake worth
     * repairing. A blind job-level retry would re-run a generation that
     * already succeeded and bill for it twice.
     */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $generationId,
    ) {}

    public function handle(LlmProvider $provider): void
    {
        $generation = AiGeneration::find($this->generationId);

        if ($generation === null || $generation->isFinished()) {
            return;
        }

        $generation = (new FormGenerator($provider))->run($generation);

        if (! $generation->succeeded()) {
            return;
        }

        $generation->mode === 'edit'
            ? $this->applyEdit($generation)
            : $this->createForm($generation);
    }

    /**
     * Create a brand-new form from the generated schema.
     */
    private function createForm(AiGeneration $generation): void
    {
        $schema = $generation->result_schema;

        DB::transaction(function () use ($generation, $schema) {
            $form = Form::create([
                'user_id' => $generation->user_id,
                'title' => $schema['title'],
                'description' => $schema['description'] ?? null,
                // Never auto-publish. A generated form is a draft until a human
                // has looked at it.
                'status' => FormStatus::Draft,
            ]);

            $version = FormVersion::create([
                'form_id' => $form->id,
                'version_number' => 1,
                'schema' => $schema,
                'checksum' => FormVersion::checksumFor($schema),
                'origin' => 'ai_create',
                'note' => 'Generated from: '.\Illuminate\Support\Str::limit($generation->prompt, 200),
                'created_by' => $generation->user_id,
            ]);

            $form->update(['current_version_id' => $version->id]);

            // Links the audit row to what it produced, so the UI can redirect
            // and the history shows where a form came from.
            $generation->update(['form_id' => $form->id]);
        });
    }

    /**
     * Apply an AI edit as the next version of an existing form.
     */
    private function applyEdit(AiGeneration $generation): void
    {
        $form = $generation->form;

        if ($form === null) {
            return;
        }

        $schema = $generation->result_schema;
        $checksum = FormVersion::checksumFor($schema);

        // The model may have decided nothing needed changing. Recording that
        // as a new version would be noise in the history.
        if ($form->currentVersion?->checksum === $checksum) {
            $generation->update(['error' => 'The model returned the form unchanged.']);

            return;
        }

        DB::transaction(function () use ($form, $generation, $schema, $checksum) {
            $next = ($form->versions()->max('version_number') ?? 0) + 1;

            $version = FormVersion::create([
                'form_id' => $form->id,
                'version_number' => $next,
                'schema' => $schema,
                'checksum' => $checksum,
                'origin' => 'ai_edit',
                'note' => \Illuminate\Support\Str::limit($generation->prompt, 200),
                'created_by' => $generation->user_id,
            ]);

            $form->update([
                'current_version_id' => $version->id,
                'title' => $schema['title'],
                'description' => $schema['description'] ?? null,
            ]);
        });
    }

    /**
     * A crash outside FormGenerator's own handling still has to close the row,
     * or the UI would poll a "running" generation forever.
     */
    public function failed(?Throwable $e): void
    {
        AiGeneration::where('id', $this->generationId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error' => $e?->getMessage() ?? 'The job failed unexpectedly.',
            ]);
    }
}
