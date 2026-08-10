<?php

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Database\Seeders\DemoUserSeeder;

/**
 * The public submit path.
 *
 * This is the one surface strangers touch, so the tests lean on the adversarial
 * cases: payloads the browser would never send, keys the schema never declared,
 * and files the form never allowed.
 */

beforeEach(function () {
    $this->seed(Database\Seeders\DatabaseSeeder::class);

    $this->user = User::where('email', DemoUserSeeder::EMAIL)->firstOrFail();
    $this->form = $this->user->forms()->firstOrFail();

    $this->valid = [
        'full_name' => 'Priya Sharma',
        'email' => 'priya@example.com',
        'phone' => '+91 98765 43210',
        'institution' => 'IIT Delhi',
        'degree' => 'btech',
        'skills' => ['php', 'mysql'],
        'experience' => 'Built a ticketing system.',
    ];
});

function submit(array $data, array $files = [])
{
    return test()->post(
        route('public.form.submit', test()->form->slug),
        array_merge($data, $files)
    );
}

// ── Happy path ───────────────────────────────────────────────────────────

it('stores a valid submission and redirects to the thank-you page', function () {
    Storage::fake('local');

    $before = FormSubmission::count();

    submit($this->valid, ['resume' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertRedirect(route('public.form.success', $this->form->slug));

    expect(FormSubmission::count())->toBe($before + 1);

    $submission = FormSubmission::latest('id')->first();

    expect($submission->data['full_name'])->toBe('Priya Sharma')
        ->and($submission->data['skills'])->toBe(['php', 'mysql'])
        ->and($submission->form_version_id)->toBe($this->form->current_version_id);
});

it('records the version it was answered against', function () {
    Storage::fake('local');

    submit($this->valid, ['resume' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')]);

    $submission = FormSubmission::latest('id')->first();

    // Editing the form afterwards must not change how this reads back.
    expect($submission->version->version_number)->toBe($this->form->currentVersion->version_number);
});

it('stores an uploaded file and links it to the field', function () {
    Storage::fake('local');

    submit($this->valid, ['resume' => UploadedFile::fake()->create('my cv.pdf', 50, 'application/pdf')]);

    $file = FormSubmission::latest('id')->first()->files->first();

    expect($file->field_key)->toBe('resume')
        ->and($file->original_name)->toBe('my cv.pdf');

    Storage::disk('local')->assertExists($file->path);

    // The respondent's filename is recorded but never used as a path.
    expect($file->path)->not->toContain('my cv.pdf');
});

it('increments the denormalised counter', function () {
    Storage::fake('local');

    $before = $this->form->submission_count;

    submit($this->valid, ['resume' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')]);

    expect($this->form->fresh()->submission_count)->toBe($before + 1);
});

it('builds the search index from the answers', function () {
    Storage::fake('local');

    submit($this->valid, ['resume' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')]);

    $submission = FormSubmission::latest('id')->first();

    expect($submission->search_text)->toContain('Priya Sharma')
        ->and($submission->search_text)->toContain('priya@example.com');
});

// ── Server-side validation: never trust the browser ─────────────────────

it('rejects a submission missing a required field', function () {
    $payload = $this->valid;
    unset($payload['full_name']);

    submit($payload)->assertSessionHasErrors('full_name');

    expect(FormSubmission::count())->toBe(3); // only the seeded three
});

it('rejects a choice value that is not in the option list', function () {
    // The browser would never send this; a crafted POST would.
    submit(array_merge($this->valid, ['degree' => 'honorary_doctorate']))
        ->assertSessionHasErrors('degree');
});

it('rejects a checkbox value that is not in the option list', function () {
    submit(array_merge($this->valid, ['skills' => ['php', 'cobol']]))
        ->assertSessionHasErrors('skills.1');
});

it('rejects a malformed email', function () {
    submit(array_merge($this->valid, ['email' => 'not-an-email']))
        ->assertSessionHasErrors('email');
});

it('rejects a phone number that fails the schema regex', function () {
    submit(array_merge($this->valid, ['phone' => 'call me maybe!!']))
        ->assertSessionHasErrors('phone');
});

it('rejects an upload with a disallowed extension', function () {
    Storage::fake('local');

    submit($this->valid, ['resume' => UploadedFile::fake()->create('payload.exe', 10)])
        ->assertSessionHasErrors('resume');

    expect(FormSubmission::count())->toBe(3);
});

it('rejects an upload above the size limit', function () {
    Storage::fake('local');

    // The schema allows 5120 KB.
    submit($this->valid, ['resume' => UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf')])
        ->assertSessionHasErrors('resume');
});

it('discards keys the schema never declared', function () {
    Storage::fake('local');

    submit(array_merge($this->valid, [
        'is_admin' => 1,
        'user_id' => 99,
        'injected' => 'nope',
    ]), ['resume' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')]);

    $data = FormSubmission::latest('id')->first()->data;

    // Laravel silently ignores extra keys it has no rule for, so the explicit
    // allow-list in partition() is what actually stops this.
    expect($data)->not->toHaveKey('is_admin')
        ->and($data)->not->toHaveKey('user_id')
        ->and($data)->not->toHaveKey('injected');
});

// ── Publication state ────────────────────────────────────────────────────

it('refuses submissions to a form that is not published', function () {
    $this->form->update(['status' => 'draft']);

    submit($this->valid)->assertNotFound();
});

// ── Spam ─────────────────────────────────────────────────────────────────

it('silently swallows a submission that fills the honeypot', function () {
    $before = FormSubmission::count();

    // Accepted-looking so the bot does not learn it was caught.
    submit(array_merge($this->valid, ['_hp' => 'http://spam.example']))
        ->assertRedirect(route('public.form.success', $this->form->slug));

    expect(FormSubmission::count())->toBe($before);
});

// ── Redirect setting ─────────────────────────────────────────────────────

it('redirects away when the form defines a redirect URL', function () {
    Storage::fake('local');

    $schema = $this->form->schema();
    $schema['settings']['redirect_url'] = 'https://example.com/thanks';

    $version = FormVersion::create([
        'form_id' => $this->form->id,
        'version_number' => 2,
        'schema' => $schema,
        'checksum' => FormVersion::checksumFor($schema),
        'origin' => 'manual',
        'created_by' => $this->user->id,
    ]);
    $this->form->update(['current_version_id' => $version->id]);

    submit($this->valid, ['resume' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')])
        ->assertRedirect('https://example.com/thanks');
});
