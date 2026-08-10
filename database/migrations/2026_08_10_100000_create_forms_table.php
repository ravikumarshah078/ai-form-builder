<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `forms` table is the stable identity of a form: who owns it, what it is
 * called, and where the public can reach it.
 *
 * Deliberately, it does NOT hold the field definitions. Those live in
 * `form_versions.schema`, because a form's shape changes over time while its
 * identity (and therefore its public URL) must not. See that migration for the
 * full reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();

            // Public-safe identifier. We never expose the auto-increment id in
            // a URL or API response, so record counts stay private.
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 200 chars matches the counter shown in the assignment's
            // reference UI ("0/200").
            $table->string('title', 200);

            // The public fill URL is /f/{slug}. Longer than the title to leave
            // room for the numeric suffix we append on collision.
            $table->string('slug', 220)->unique();

            $table->text('description')->nullable();

            $table->enum('status', ['draft', 'published', 'archived'])
                ->default('draft');

            // Points at the row in `form_versions` that is currently live.
            // The FK is added in a later migration because `form_versions`
            // does not exist yet at this point.
            $table->unsignedBigInteger('current_version_id')->nullable();

            // Form-level behaviour: submit button label, redirect target,
            // notification recipients, whether it renders as multi-step.
            $table->json('settings')->nullable();

            // Denormalised counter. The submissions list and the dashboard both
            // want "how many responses" and a COUNT(*) over a large
            // form_submissions table on every page load is wasteful. Maintained
            // by the submission observer.
            $table->unsignedInteger('submission_count')->default(0);

            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The dashboard query: this user's forms, optionally filtered by
            // status, newest first. Composite so MySQL can satisfy the filter
            // and the sort from one index.
            $table->index(['user_id', 'status', 'created_at'], 'forms_owner_listing_idx');

            // Admin-wide listing of live forms.
            $table->index(['status', 'published_at'], 'forms_published_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
