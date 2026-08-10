<?php

use App\Models\User;

/**
 * Whole-page render smoke tests.
 *
 * These exist because of a real bug: the layout used @yield('content') while
 * full-page Livewire components render into {{ $slot }}. Every page returned
 * 200 with correct chrome and a correct <title>, and a completely empty body.
 * A status-code assertion could never have caught it.
 *
 * So each test here asserts on CONTENT that only the component can produce.
 */

beforeEach(function () {
    $this->seed(Database\Seeders\DatabaseSeeder::class);

    $this->user = User::where('email', Database\Seeders\DemoUserSeeder::EMAIL)->firstOrFail();
    $this->form = $this->user->forms()->firstOrFail();
});

it('renders the login page', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Sign in')
        ->assertSee('Password');
});

it('renders the forms dashboard with the seeded form', function () {
    $this->actingAs($this->user)
        ->get('/forms')
        ->assertOk()
        ->assertSee('Internship Application')
        ->assertSee('Published');
});

it('renders wizard step 1 with its form fields', function () {
    $this->actingAs($this->user)
        ->get('/forms/create')
        ->assertOk()
        ->assertSee('Form basics')
        ->assertSee('Public URL')
        ->assertSee('Next: Builder');
});

it('renders the builder canvas with the palette and existing fields', function () {
    $response = $this->actingAs($this->user)
        ->get(route('forms.build', $this->form))
        ->assertOk()
        ->assertSee('Add fields')
        ->assertSee('Options')
        // The AI edit tab, added in Part B.
        ->assertSee('AI');

    $html = $response->getContent();

    // One sortable container per section, one draggable card per field.
    expect(substr_count($html, 'data-sortable-section='))->toBe($this->form->currentVersion->schema['sections'] ? 3 : 0)
        ->and(substr_count($html, 'data-field-id='))->toBe(9)
        // The palette is driven by the FieldType enum, so this count is the
        // enum's size and will move if a type is added.
        ->and(substr_count($html, 'fb-palette__item'))->toBe(count(App\Enums\FieldType::cases()));
});

it('renders the AI generation page', function () {
    $this->actingAs($this->user)
        ->get(route('forms.ai'))
        ->assertOk()
        ->assertSee('Describe the form you need')
        // The example from the brief is offered as a one-click prompt.
        ->assertSee('internship application with education history');
});

it('renders the public fill page from the schema snapshot', function () {
    $this->get(route('public.form.show', $this->form))
        ->assertOk()
        ->assertSee('Internship Application')
        ->assertSee('Full name')
        ->assertSee('Education history')
        ->assertSee('name="skills[]', false);
});

it('404s a public form that is not published', function () {
    $this->form->update(['status' => 'draft']);

    // 404 rather than 403: a 403 would confirm the slug exists.
    $this->get(route('public.form.show', $this->form))->assertNotFound();
});

it('redirects a guest away from the builder', function () {
    $this->get('/forms')->assertRedirect('/login');
});

it('stops one user opening another user\'s form', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('forms.build', $this->form))
        ->assertForbidden();
});
