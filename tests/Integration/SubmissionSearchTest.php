<?php

use App\Models\User;
use Database\Seeders\DemoUserSeeder;

/**
 * Submission search, against a real committed MySQL FULLTEXT index.
 *
 * These live in tests/Integration rather than tests/Feature for one concrete
 * reason: InnoDB does not update a FULLTEXT index until the writing
 * transaction commits. RefreshDatabase wraps each test in a transaction and
 * rolls it back, so MATCH ... AGAINST would find nothing a test had just
 * inserted — the tests would fail while the feature worked perfectly in
 * production.
 *
 * The Integration suite truncates instead of transacting, so the index is
 * genuinely built and these tests exercise the same code path a real request
 * does. See tests/Pest.php.
 */

beforeEach(function () {
    $this->seed(Database\Seeders\DatabaseSeeder::class);

    $this->user = User::where('email', DemoUserSeeder::EMAIL)->firstOrFail();
    $this->form = $this->user->forms()->firstOrFail();

    $this->actingAs($this->user);
});

it('finds a response by an answer value', function () {
    $this->get(route('forms.submissions', ['form' => $this->form, 'q' => 'Priya']))
        ->assertOk()
        ->assertSee('Priya Sharma')
        ->assertDontSee('Arjun Mehta');
});

it('matches a partial word', function () {
    // Boolean mode with a trailing wildcard, so search behaves as you type.
    $this->get(route('forms.submissions', ['form' => $this->form, 'q' => 'Meh']))
        ->assertOk()
        ->assertSee('Arjun Mehta')
        ->assertDontSee('Priya Sharma');
});

it('searches across every field, not just the first', function () {
    // "Anna University" only appears in the institution field.
    $this->get(route('forms.submissions', ['form' => $this->form, 'q' => 'Anna']))
        ->assertOk()
        ->assertSee('Fatima Khan');
});

it('requires all terms to match', function () {
    // Each term is prefixed with + in boolean mode, so this is an AND.
    $this->get(route('forms.submissions', ['form' => $this->form, 'q' => 'Priya Mumbai']))
        ->assertOk()
        ->assertDontSee('Priya Sharma');
});

it('returns nothing for a term that matches no answer', function () {
    $this->get(route('forms.submissions', ['form' => $this->form, 'q' => 'zzzznotpresent']))
        ->assertOk()
        ->assertDontSee('Priya Sharma');
});

it('survives punctuation that would otherwise break boolean mode', function (string $term) {
    // A bare +, -, ~ or " is a MySQL boolean operator. Unescaped, these are a
    // syntax error, which would be a 500 on a public-facing search box.
    $this->get(route('forms.submissions', ['form' => $this->form, 'q' => $term]))
        ->assertOk();
})->with(['+', '-', '~', '"', '@', '()', '+++', '* * *', 'a"b(c)']);

it('honours the search term when exporting to CSV', function () {
    $csv = $this->get(route('forms.submissions.export', ['form' => $this->form, 'q' => 'Priya']))
        ->streamedContent();

    expect($csv)->toContain('Priya Sharma')
        ->and($csv)->not->toContain('Arjun Mehta');
});
