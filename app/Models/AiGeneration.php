<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One LLM call: its inputs, its cost, and its outcome.
 *
 * Doubles as the queued job's status record — the browser polls this row's
 * `status` to drive the progress indicator, which is why the UI never needs a
 * separate job-tracking table.
 */
class AiGeneration extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'form_id',
        'mode',
        'prompt',
        'input_schema',
        'provider',
        'model',
        'status',
        'attempts',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'raw_response',
        'result_schema',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'result_schema' => 'array',
            'attempts' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiGeneration $generation) {
            $generation->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    // ── State helpers, used by the polling endpoint and the Livewire view ──

    public function isFinished(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function totalTokens(): int
    {
        return (int) $this->input_tokens + (int) $this->output_tokens;
    }
}
