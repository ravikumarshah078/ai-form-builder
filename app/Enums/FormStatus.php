<?php

namespace App\Enums;

/**
 * Lifecycle of a form.
 *
 * Only a Published form accepts submissions on its public URL. Draft forms are
 * reachable by their owner for preview but return 404 to the public, so a
 * half-built form can never quietly collect real data.
 */
enum FormStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Bootstrap badge class, so status rendering lives with the status.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-secondary',
            self::Published => 'bg-success',
            self::Archived => 'bg-dark',
        };
    }

    /**
     * Whether the public fill URL should serve this form.
     */
    public function acceptsSubmissions(): bool
    {
        return $this === self::Published;
    }
}
