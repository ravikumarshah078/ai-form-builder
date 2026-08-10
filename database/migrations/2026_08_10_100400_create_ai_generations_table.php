<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for every call to the LLM (Part B).
 *
 * The brief asks us to "log model, tokens and latency", and to run generation
 * as a queued job with visible status. This table does both jobs at once: it
 * IS the observability record, and it IS the state the UI polls to show
 * progress. That avoids a second job-status mechanism sitting alongside the
 * log.
 *
 * `raw_response` is kept even on success. When an LLM returns malformed JSON,
 * the only way to improve the repair step is to read exactly what came back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();

            // The handle the browser polls; safe to expose.
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->nullable()
                ->constrained()->nullOnDelete();

            // Null for "create from scratch", set for "edit this form".
            $table->foreignId('form_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->enum('mode', ['create', 'edit']);

            // What the user typed.
            $table->text('prompt');

            // For an edit, the schema we asked the model to modify. Stored so a
            // failed edit can be diffed against its input.
            $table->json('input_schema')->nullable();

            $table->string('provider', 50)->default('anthropic');
            $table->string('model', 100);

            $table->enum('status', ['queued', 'running', 'succeeded', 'failed'])
                ->default('queued');

            // Incremented on each retry after a malformed-JSON response, so we
            // can measure how often the repair path fires.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            // Wall-clock time of the provider call, excluding queue wait.
            $table->unsignedInteger('latency_ms')->nullable();

            // Exactly what the provider returned, before parsing or repair.
            $table->longText('raw_response')->nullable();

            // The validated schema. Only written once it passes validation, so
            // a broken schema can never reach `form_versions` through here.
            $table->json('result_schema')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();

            // "My recent generations", newest first.
            $table->index(['user_id', 'created_at'], 'ai_generations_user_idx');

            // Ops view: what is stuck queued or failing.
            $table->index(['status', 'created_at'], 'ai_generations_status_idx');

            // Cost and latency reporting per model.
            $table->index(['model', 'created_at'], 'ai_generations_model_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};
