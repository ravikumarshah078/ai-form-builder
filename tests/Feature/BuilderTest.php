<?php

use App\Livewire\Forms\Builder;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Livewire\Livewire;

/**
 * The builder canvas.
 *
 * These exercise the component the way the UI does — click a palette button,
 * drag a field, edit JSON — rather than calling the schema layer directly,
 * which the unit tests already cover.
 */

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->form = Form::create([
        'user_id' => $this->user->id,
        'title' => 'Test form',
        'status' => 'draft',
    ]);

    $schema = App\Forms\FieldFactory::emptySchema('Test form');

    $version = FormVersion::create([
        'form_id' => $this->form->id,
        'version_number' => 1,
        'schema' => $schema,
        'checksum' => FormVersion::checksumFor($schema),
        'origin' => 'manual',
        'created_by' => $this->user->id,
    ]);

    $this->form->update(['current_version_id' => $version->id]);

    $this->actingAs($this->user);
});

/**
 * Leading backslash matters: without it PHP resolves the name against the
 * imported `Livewire\Livewire` alias and looks for Livewire\Livewire\Features\…
 */
function builder(): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(Builder::class, ['form' => test()->form]);
}

// ── Access control ───────────────────────────────────────────────────────

it('refuses to open a form owned by someone else', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Builder::class, ['form' => $this->form])->assertForbidden();
});

// ── Click to add ─────────────────────────────────────────────────────────

it('adds a field from the palette and creates a section implicitly', function () {
    builder()
        ->assertSet('schema.sections', [])
        ->call('addField', 'text')
        ->assertCount('schema.sections', 1)
        ->assertCount('schema.sections.0.fields', 1)
        ->assertSet('schema.sections.0.fields.0.type', 'text')
        // The new field is selected and the panel switches, so the user lands
        // where they can configure what they just added.
        ->assertSet('tab', 'options');
});

it('gives every added field a unique key', function () {
    $component = builder()
        ->call('addField', 'text')
        ->call('addField', 'text')
        ->call('addField', 'text');

    $keys = array_column($component->get('schema.sections.0.fields'), 'key');

    expect($keys)->toBe(array_unique($keys))->and($keys)->toHaveCount(3);
});

it('ignores an unknown field type from the palette', function () {
    builder()->call('addField', 'quantum_widget')->assertSet('schema.sections', []);
});

// ── Duplicate, delete, reorder ───────────────────────────────────────────

it('duplicates a field with a fresh id and key', function () {
    $c = builder()->call('addField', 'email');

    $original = $c->get('schema.sections.0.fields.0');

    $c->call('duplicateField', $original['id']);

    $fields = $c->get('schema.sections.0.fields');

    expect($fields)->toHaveCount(2)
        ->and($fields[1]['id'])->not->toBe($original['id'])
        ->and($fields[1]['key'])->not->toBe($original['key'])
        ->and($fields[1]['type'])->toBe('email');
});

it('deletes a field and clears the selection', function () {
    $c = builder()->call('addField', 'text');
    $id = $c->get('schema.sections.0.fields.0.id');

    $c->call('deleteField', $id)
        ->assertCount('schema.sections.0.fields', 0)
        ->assertSet('selectedFieldId', null);
});

it('reorders fields within a section', function () {
    $c = builder()
        ->call('addField', 'text')
        ->call('addField', 'email')
        ->call('addField', 'phone');

    $fields = $c->get('schema.sections.0.fields');
    $lastId = $fields[2]['id'];

    // Drag the third field to the front.
    $c->call('moveField', $lastId, 0, 0);

    expect(array_column($c->get('schema.sections.0.fields'), 'type'))
        ->toBe(['phone', 'text', 'email']);
});

it('moves a field between sections', function () {
    $c = builder()
        ->call('addField', 'text')
        ->call('addSection');

    $id = $c->get('schema.sections.0.fields.0.id');

    $c->call('moveField', $id, 1, 0);

    expect($c->get('schema.sections.0.fields'))->toHaveCount(0)
        ->and($c->get('schema.sections.1.fields'))->toHaveCount(1);
});

it('ignores a move to a section that does not exist', function () {
    $c = builder()->call('addField', 'text');
    $id = $c->get('schema.sections.0.fields.0.id');

    $c->call('moveField', $id, 99, 0);

    expect($c->get('schema.sections.0.fields'))->toHaveCount(1);
});

// ── Sections ─────────────────────────────────────────────────────────────

it('deletes a section and everything in it', function () {
    $c = builder()->call('addField', 'text')->call('addSection');

    $c->call('deleteSection', 0);

    expect($c->get('schema.sections'))->toHaveCount(1);
});

it('reorders sections', function () {
    $c = builder()->call('addSection')->call('addSection');

    $firstTitle = $c->get('schema.sections.0.title');

    $c->call('moveSection', 0, 1);

    expect($c->get('schema.sections.1.title'))->toBe($firstTitle);
});

// ── Options ──────────────────────────────────────────────────────────────

it('adds and removes options on a choice field', function () {
    $c = builder()->call('addField', 'dropdown');

    expect($c->get('schema.sections.0.fields.0.options'))->toHaveCount(2);

    $c->call('addOption');
    expect($c->get('schema.sections.0.fields.0.options'))->toHaveCount(3);

    $c->call('removeOption', 0);
    expect($c->get('schema.sections.0.fields.0.options'))->toHaveCount(2);
});

// ── Raw JSON editor: two-way sync ────────────────────────────────────────

it('serialises the canvas into the JSON tab', function () {
    $c = builder()->call('addField', 'text')->call('setTab', 'json');

    $decoded = json_decode($c->get('rawJson'), true);

    expect($decoded['sections'][0]['fields'][0]['type'])->toBe('text');
});

it('applies edited JSON back onto the canvas', function () {
    $json = json_encode([
        'version' => 1,
        'title' => 'From JSON',
        'settings' => ['multi_step' => false],
        'sections' => [[
            'title' => 'Pasted',
            'fields' => [
                ['type' => 'email', 'label' => 'Work email'],
                ['type' => 'select', 'label' => 'Team', 'options' => ['Eng', 'Sales']],
            ],
        ]],
    ]);

    $c = builder()->set('rawJson', $json)->call('applyJson');

    expect($c->get('jsonErrors'))->toBe([])
        ->and($c->get('schema.sections.0.fields'))->toHaveCount(2)
        // "select" was repaired to "dropdown" by the same normaliser the AI uses.
        ->and($c->get('schema.sections.0.fields.1.type'))->toBe('dropdown');
});

it('refuses to apply malformed JSON and leaves the canvas alone', function () {
    $c = builder()->call('addField', 'text');

    $before = $c->get('schema');

    $c->set('rawJson', '{ "title": "broken", ')->call('applyJson');

    expect($c->get('jsonErrors'))->not->toBe([])
        ->and($c->get('schema'))->toBe($before);
});

it('refuses to apply JSON that is well-formed but invalid', function () {
    $c = builder()->call('addField', 'text');

    $before = $c->get('schema');

    $c->set('rawJson', json_encode([
        'version' => 1,
        'title' => 'Bad',
        'sections' => [['fields' => [['type' => 'quantum_widget', 'label' => 'Q']]]],
    ]))->call('applyJson');

    expect($c->get('schema'))->toBe($before)
        ->and(collect($c->get('jsonErrors'))->flatten()->implode(' '))->toContain('quantum_widget');
});

it('shows the normalised result back in the editor after applying', function () {
    $c = builder()->set('rawJson', json_encode([
        'version' => 1,
        'title' => 'Norm',
        'sections' => [['fields' => [['type' => 'e-mail', 'label' => 'Email']]]],
    ]))->call('applyJson');

    // The repair is made visible rather than being a surprise on save.
    expect($c->get('rawJson'))->toContain('"email"');
});

// ── Saving ───────────────────────────────────────────────────────────────

it('writes a new version on save', function () {
    builder()->call('addField', 'text')->call('save', false);

    $this->form->refresh();

    expect($this->form->versions()->count())->toBe(2)
        ->and($this->form->currentVersion->version_number)->toBe(2)
        ->and($this->form->currentVersion->schema['sections'][0]['fields'])->toHaveCount(1);
});

it('does not write a version when nothing changed', function () {
    builder()->call('save', false);

    expect($this->form->fresh()->versions()->count())->toBe(1);
});

it('refuses to save an invalid schema', function () {
    $c = builder()->call('addField', 'dropdown');

    // Strip the options, which makes the choice field invalid.
    $c->set('schema.sections.0.fields.0.options', [])->call('save', false);

    expect($this->form->fresh()->versions()->count())->toBe(1)
        ->and($c->get('tab'))->toBe('json');
});

it('strips editor-only bookkeeping before persisting', function () {
    $c = builder()->call('addField', 'text');

    $c->set('schema.sections.0.fields.0._previous_label', 'leaked')->call('save', false);

    $saved = $this->form->fresh()->currentVersion->schema;

    expect($saved['sections'][0]['fields'][0])->not->toHaveKey('_previous_label');
});

it('keeps the form title in step with the schema title', function () {
    builder()->call('addField', 'text')
        ->set('schema.title', 'Renamed in builder')
        ->call('save', false);

    expect($this->form->fresh()->title)->toBe('Renamed in builder');
});
