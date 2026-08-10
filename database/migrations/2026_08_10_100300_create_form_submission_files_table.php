<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploads attached to a submission (the "file upload" field type).
 *
 * These are a separate table rather than blobs inside `form_submissions.data`
 * for three reasons: the JSON row stays small enough to read cheaply; a
 * failed virus scan or a retention sweep can delete a file without rewriting
 * the submission; and `disk` lets us move to S3 later by changing one column
 * rather than migrating JSON.
 *
 * `data` still records the field key pointing here, so the submission remains
 * self-describing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submission_files', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('form_submission_id')
                ->constrained()->cascadeOnDelete();

            // Which field in the schema this upload belongs to.
            $table->string('field_key', 100);

            // What the respondent called it. Never used as a filesystem path.
            $table->string('original_name', 255);

            // Laravel filesystem disk name, so local -> s3 is a config change.
            $table->string('disk', 50)->default('local');

            // Storage-relative path, always a generated name.
            $table->string('path', 500);

            $table->string('mime', 150);

            $table->unsignedBigInteger('size');

            $table->timestamps();

            // Rendering one submission's attachments.
            $table->index(['form_submission_id', 'field_key'], 'submission_files_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submission_files');
    }
};
