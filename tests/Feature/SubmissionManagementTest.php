<?php

use App\Models\FormSubmission;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;

/**
 * Listing, searching, viewing and exporting responses.
 */

beforeEach(function () {
    $this->seed(Database\Seeders\DatabaseSeeder::class);

    $this->user = User::where('email', DemoUserSeeder::EMAIL)->firstOrFail();
    $this->form = $this->user->forms()->firstOrFail();

    $this->actingAs($this->user);
});

// ── Listing ──────────────────────────────────────────────────────────────
// Search itself is covered in tests/Integration/SubmissionSearchTest.php,
// which commits rather than transacting so the FULLTEXT index is real.

it('lists the seeded responses', function () {
    $this->get(route('forms.submissions', $this->form))
        ->assertOk()
        ->assertSee('Priya Sharma')
        ->assertSee('Arjun Mehta')
        ->assertSee('Fatima Khan');
});





// ── Single response ──────────────────────────────────────────────────────

it('shows one response rendered against its own schema version', function () {
    $submission = $this->form->submissions()->first();

    $this->get(route('forms.submissions.show', [$this->form, $submission]))
        ->assertOk()
        ->assertSee('Full name')
        // Option values are mapped back to their labels.
        ->assertSee('PHP');
});

it('renders an old response with the labels the respondent saw', function () {
    $submission = $this->form->submissions()->first();

    // Rename a field and publish a new version.
    $schema = $this->form->schema();
    $schema['sections'][0]['fields'][0]['label'] = 'RENAMED LABEL';

    $version = App\Models\FormVersion::create([
        'form_id' => $this->form->id,
        'version_number' => 2,
        'schema' => $schema,
        'checksum' => App\Models\FormVersion::checksumFor($schema),
        'origin' => 'manual',
        'created_by' => $this->user->id,
    ]);
    $this->form->update(['current_version_id' => $version->id]);

    // The old response must still show the ORIGINAL label. This is the single
    // strongest argument for the immutable-versions design.
    $this->get(route('forms.submissions.show', [$this->form, $submission]))
        ->assertOk()
        ->assertSee('Full name')
        ->assertDontSee('RENAMED LABEL')
        ->assertSee('superseded');
});

// ── CSV export ───────────────────────────────────────────────────────────

it('exports responses as CSV', function () {
    $response = $this->get(route('forms.submissions.export', $this->form))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")          // BOM, so Excel reads UTF-8
        ->and($csv)->toContain('Reference')
        ->and($csv)->toContain('Full name')
        ->and($csv)->toContain('Priya Sharma')
        // Option values rendered as labels, not raw stored values.
        ->and($csv)->toContain('PHP, MySQL');
});


it('names the export after the form and date', function () {
    $this->get(route('forms.submissions.export', $this->form))
        ->assertDownload($this->form->slug.'-responses-'.now()->format('Y-m-d').'.csv');
});

// ── Deleting ─────────────────────────────────────────────────────────────

it('deletes a response and decrements the counter', function () {
    $submission = $this->form->submissions()->first();
    $before = $this->form->submission_count;

    $this->delete(route('forms.submissions.destroy', [$this->form, $submission]))
        ->assertRedirect(route('forms.submissions', $this->form));

    expect(FormSubmission::find($submission->id))->toBeNull()
        ->and($this->form->fresh()->submission_count)->toBe($before - 1);
});

// ── Access control ───────────────────────────────────────────────────────

it('stops another user reading responses', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('forms.submissions', $this->form))
        ->assertForbidden();
});

it('stops another user exporting responses', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('forms.submissions.export', $this->form))
        ->assertForbidden();
});

it('404s a submission that belongs to a different form', function () {
    $other = $this->user->forms()->create([
        'title' => 'Another form',
        'status' => 'draft',
    ]);

    $submission = $this->form->submissions()->first();

    $this->get(route('forms.submissions.show', [$other, $submission]))->assertNotFound();
});
