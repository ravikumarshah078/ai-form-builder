<?php

namespace App\Models;

use App\Observers\FormSubmissionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One completed public fill of a form.
 *
 * Always belongs to a specific FormVersion, never just to a Form. That is what
 * lets us render a two-month-old submission with the labels the respondent
 * actually saw, even after the form has been edited ten times since.
 */
#[ObservedBy(FormSubmissionObserver::class)]
class FormSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'form_version_id',
        'data',
        'meta',
        'status',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'meta' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FormSubmission $submission) {
            $submission->uuid ??= (string) Str::uuid();
            $submission->submitted_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * The exact schema this was answered against.
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(FormSubmissionFile::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    /**
     * Free-text search across all answers.
     *
     * Uses the FULLTEXT index on `search_text` in boolean mode, appending `*`
     * so partial words match as the user types. Falls back to nothing when the
     * term is empty rather than returning an unfiltered set by accident.
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // Strip boolean-mode operators so a user typing "+" or "-" cannot
        // produce a MySQL syntax error or an unintended negation.
        $clean = preg_replace('/[+\-><()~*"@]+/', ' ', $term);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        if ($clean === '') {
            return $query;
        }

        $boolean = collect(explode(' ', $clean))
            ->map(fn (string $word) => '+'.$word.'*')
            ->implode(' ');

        return $query->whereRaw(
            'MATCH(search_text) AGAINST (? IN BOOLEAN MODE)',
            [$boolean]
        );
    }

    /**
     * Ordered to match submissions_listing_idx (form_id, submitted_at).
     */
    public function scopeNewestFirst($query)
    {
        return $query->orderByDesc('submitted_at')->orderByDesc('id');
    }
}
