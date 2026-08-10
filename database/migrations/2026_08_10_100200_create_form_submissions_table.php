<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per completed public form fill.
 *
 * Answers are stored as JSON keyed by the field `key` from the schema, not in
 * a normalised answers table. The trade-off is deliberate and is written up in
 * DECISIONS.md: a normalised `submission_answers` table would make cross-form
 * answer queries easy but turns every read of a 40-field submission into a
 * 40-row join, and it cannot represent a checkbox group or a repeating group
 * without further tables. JSON keeps a submission a single row read.
 *
 * The cost of that choice is search, which JSON columns index poorly. We pay
 * it back with `search_text` (see below) rather than by normalising.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();

            // Given to the respondent as a receipt reference, so it must not
            // leak how many submissions exist.
            $table->uuid('uuid')->unique();

            $table->foreignId('form_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete, not cascade: a schema version that has
            // submissions attached must not be deletable, or we would lose the
            // ability to render those answers with their original labels.
            $table->foreignId('form_version_id')
                ->constrained('form_versions')->restrictOnDelete();

            // Answers, keyed by field key: {"full_name": "Jane", "skills": ["php","sql"]}
            $table->json('data');

            // Flattened, space-joined copy of every answer value, maintained on
            // write. MySQL cannot FULLTEXT-index a JSON column, and
            // `WHERE data->>'$.x' LIKE '%term%'` is a full table scan. This
            // column gives the submissions list a real index for its search
            // box. Denormalised on purpose; rebuilt from `data` if it drifts.
            $table->mediumText('search_text')->nullable();

            // Request context: ip, user agent, referrer, time-to-complete.
            // Kept out of `data` so respondent answers stay clean for export.
            $table->json('meta')->nullable();

            // 'partial' supports the save-and-resume / drop-off analytics work
            // in Part D without a later migration.
            $table->enum('status', ['complete', 'partial'])->default('complete');

            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            // The submissions list: one form, newest first, paginated. This is
            // the single hottest query in the app.
            $table->index(['form_id', 'submitted_at'], 'submissions_listing_idx');

            // Same list filtered by status, and the drop-off funnel query.
            $table->index(['form_id', 'status'], 'submissions_status_idx');

            // Powers the search box in natural-language mode.
            $table->fullText('search_text', 'submissions_search_ft');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
