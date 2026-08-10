<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A .docx / .xlsx upload working its way towards becoming a form.
 *
 * Holds both the deterministic parse and the AI-refined proposal so the
 * mapping screen can show them side by side and the user can reject an AI
 * inference without re-uploading.
 */
class DocumentImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'form_id',
        'source',
        'original_filename',
        'disk',
        'path',
        'size',
        'status',
        'parsed_schema',
        'proposed_schema',
        'warnings',
        'stats',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'parsed_schema' => 'array',
            'proposed_schema' => 'array',
            'warnings' => 'array',
            'stats' => 'array',
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DocumentImport $import) {
            $import->uuid ??= (string) Str::uuid();
        });

        // The uploaded document is only needed until the import is committed.
        static::deleted(function (DocumentImport $import) {
            Storage::disk($import->disk)->delete($import->path);
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

    /**
     * The schema the mapping screen should edit: the AI-refined proposal if we
     * got one, otherwise the deterministic parse. Never null once parsing has
     * run, so the review UI always has something to show even when the AI step
     * failed or was skipped.
     *
     * @return array<string, mixed>|null
     */
    public function workingSchema(): ?array
    {
        return $this->proposed_schema ?? $this->parsed_schema;
    }

    public function awaitingReview(): bool
    {
        return $this->status === 'awaiting_review';
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['committed', 'failed'], true);
    }
}
