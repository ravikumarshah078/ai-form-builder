<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file uploaded through a "file upload" field.
 */
class FormSubmissionFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_submission_id',
        'field_key',
        'original_name',
        'disk',
        'path',
        'mime',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FormSubmissionFile $file) {
            $file->uuid ??= (string) Str::uuid();
        });

        // Remove the physical file when the record goes, so a deleted
        // submission does not leave orphaned uploads on disk.
        static::deleted(function (FormSubmissionFile $file) {
            Storage::disk($file->disk)->delete($file->path);
        });
    }

    /**
     * Download URLs use the uuid, so a sequential id never appears in a link
     * that gets pasted into an email.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }
}
