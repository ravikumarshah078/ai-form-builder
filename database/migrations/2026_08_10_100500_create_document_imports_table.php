<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A .docx / .xlsx upload on its way to becoming a form (Part C).
 *
 * The brief requires a preview-and-mapping screen before anything is
 * committed, which means an import is a multi-step conversation with the user,
 * not a single request. This table is that conversation's state.
 *
 * It holds TWO schemas on purpose, because the brief asks us to explain the
 * split between deterministic parsing and AI inference:
 *
 *   parsed_schema   - what the deterministic parser alone extracted. Headings
 *                     to sections, numbered questions to fields, bullet lists
 *                     to options. No LLM involved, fully reproducible.
 *   proposed_schema - that same structure after the AI has inferred types and
 *                     validation rules for the ambiguous parts, and after the
 *                     user has corrected anything wrong on the mapping screen.
 *
 * Keeping both means the mapping screen can show "we detected X, AI suggested
 * Y" side by side, and a bad AI inference can always be reverted to the
 * deterministic reading without re-uploading the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_imports', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Set only once the import is committed and a form exists.
            $table->foreignId('form_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->enum('source', ['docx', 'xlsx']);

            $table->string('original_filename', 255);
            $table->string('disk', 50)->default('local');
            $table->string('path', 500);
            $table->unsignedBigInteger('size');

            // awaiting_review is the pause for the mapping screen.
            $table->enum('status', [
                'queued', 'parsing', 'awaiting_review', 'committed', 'failed',
            ])->default('queued');

            $table->json('parsed_schema')->nullable();
            $table->json('proposed_schema')->nullable();

            // Blocks we could not interpret, each with a reason and the source
            // text. Surfaced on the mapping screen so nothing fails silently.
            $table->json('warnings')->nullable();

            // Counts for the preview header: sections found, fields detected,
            // how many types came from AI vs the deterministic parser.
            $table->json('stats')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();

            // "My imports", and the resume-where-I-left-off lookup.
            $table->index(['user_id', 'status'], 'document_imports_user_idx');

            // Ops view / stuck-job sweep.
            $table->index(['status', 'created_at'], 'document_imports_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_imports');
    }
};
