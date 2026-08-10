<?php

namespace App\Models;

use App\Enums\FormStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A form's stable identity.
 *
 * Note what is NOT here: fields. Ask a Form for its shape and it delegates to
 * its current FormVersion. That indirection is the whole point of the design —
 * see the form_versions migration.
 */
class Form extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'description',
        'status',
        'current_version_id',
        'settings',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FormStatus::class,
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Assign the uuid and a unique slug on create.
     *
     * The slug is derived from the title but must stay unique across the whole
     * table because it is the public URL. On collision we append a short
     * random suffix rather than an incrementing counter, so slugs do not leak
     * how many forms share a title.
     */
    protected static function booted(): void
    {
        static::creating(function (Form $form) {
            $form->uuid ??= (string) Str::uuid();
            $form->slug ??= static::generateUniqueSlug($form->title);
        });
    }

    public static function generateUniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'form';
        $base = Str::limit($base, 200, '');

        $slug = $base;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    /**
     * Public URLs use the slug, not the id.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Full version history, newest first.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderByDesc('version_number');
    }

    /**
     * The version currently served to the public.
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'current_version_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    public function documentImports(): HasMany
    {
        return $this->hasMany(DocumentImport::class);
    }

    // ── Convenience ──────────────────────────────────────────────────────

    /**
     * The live JSON schema, or an empty skeleton for a form that has no
     * version yet (i.e. one created but never saved).
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return $this->currentVersion?->schema ?? [
            'version' => 1,
            'title' => $this->title,
            'description' => $this->description,
            'settings' => [],
            'sections' => [],
        ];
    }

    public function publicUrl(): string
    {
        return route('public.form.show', $this->slug);
    }

    public function isPublished(): bool
    {
        return $this->status->acceptsSubmissions();
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    /**
     * Ordered to match forms_owner_listing_idx (user_id, status, created_at).
     */
    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopePublished($query)
    {
        return $query->where('status', FormStatus::Published);
    }
}
