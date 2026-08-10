<?php

use App\Forms\SchemaNormaliser;
use App\Forms\SchemaValidator;

/**
 * SchemaNormaliser is what stands between "the LLM returned something roughly
 * right" and a wasted retry. Every alias in these tests is a real shape a
 * model or a document parser produces.
 *
 * The governing rule, asserted at the bottom: repair only what is
 * unambiguous. Never guess at intent.
 */

beforeEach(function () {
    $this->normaliser = new SchemaNormaliser;
    $this->validator = new SchemaValidator;
});

// ── Structure repair ─────────────────────────────────────────────────────

it('wraps a flat field list into a single section', function () {
    $out = $this->normaliser->normalise([
        'title' => 'Flat',
        'fields' => [['type' => 'text', 'label' => 'Name']],
    ]);

    expect($out['sections'])->toHaveCount(1)
        ->and($out['sections'][0]['fields'])->toHaveCount(1);
});

it('unwraps a payload nested under a wrapper key', function (string $wrapper) {
    $out = $this->normaliser->normalise([
        $wrapper => [
            'title' => 'Wrapped',
            'sections' => [['title' => 'S', 'fields' => [['type' => 'text', 'label' => 'Name']]]],
        ],
    ]);

    expect($out['title'])->toBe('Wrapped')
        ->and($out['sections'])->toHaveCount(1);
})->with(['form', 'schema', 'data', 'result']);

it('generates ids for every section and field that lacks one', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'sections' => [['fields' => [['type' => 'text', 'label' => 'A'], ['type' => 'text', 'label' => 'B']]]],
    ]);

    expect($out['sections'][0]['id'])->toMatch('/^sec_[a-z0-9]{4,32}$/')
        ->and($out['sections'][0]['fields'][0]['id'])->toMatch('/^fld_[a-z0-9]{4,32}$/')
        ->and($out['sections'][0]['fields'][1]['id'])->not->toBe($out['sections'][0]['fields'][0]['id']);
});

it('supplies defaults for a completely empty input', function () {
    $out = $this->normaliser->normalise([], 'Fallback title');

    expect($out['title'])->toBe('Fallback title')
        ->and($out['version'])->toBe(1)
        ->and($out['sections'])->toBe([])
        ->and($out['settings'])->toHaveKeys(['multi_step', 'submit_label', 'success_message', 'redirect_url']);
});

// ── Type aliases: the hallucination absorber ─────────────────────────────

it('maps a plausible type alias onto the real field type', function (string $given, string $expected) {
    $out = $this->normaliser->normalise([
        'title' => 'Aliases',
        'fields' => [['type' => $given, 'label' => 'A field', 'options' => ['One', 'Two']]],
    ]);

    expect($out['sections'][0]['fields'][0]['type'])->toBe($expected);
})->with([
    ['string', 'text'],
    ['short_text', 'text'],
    ['long_text', 'textarea'],
    ['text_area', 'textarea'],
    ['integer', 'number'],
    ['e-mail', 'email'],
    ['email_address', 'email'],
    ['tel', 'phone'],
    ['telephone', 'phone'],
    ['website', 'url'],
    ['select', 'dropdown'],
    ['single_select', 'dropdown'],
    ['radio_buttons', 'radio'],
    ['multiselect', 'checkbox'],
    ['boolean', 'checkbox'],
    ['stars', 'rating'],
    ['scale', 'rating'],
    ['upload', 'file'],
    ['attachment', 'file'],
    ['title', 'heading'],
    ['description', 'paragraph'],
    ['separator', 'divider'],
    ['DATE_PICKER', 'date'],
    ['Date Picker', 'date'],
    ['date-picker', 'date'],
]);

it('leaves a genuinely unknown type alone for the validator to reject', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'quantum_widget', 'label' => 'Q']],
    ]);

    // Repairing this would mean guessing, and a wrong guess is worse than an
    // honest failure the user can see.
    $result = $this->validator->validate($out);

    expect($result->fails())->toBeTrue()
        ->and($result->first())->toContain('quantum_widget');
});

// ── Options ──────────────────────────────────────────────────────────────

it('converts bare string options into value/label objects', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'dropdown', 'label' => 'Country', 'options' => ['India', 'United Kingdom']]],
    ]);

    expect($out['sections'][0]['fields'][0]['options'])->toBe([
        ['value' => 'india', 'label' => 'India'],
        ['value' => 'united_kingdom', 'label' => 'United Kingdom'],
    ]);
});

it('accepts a value-keyed options object', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'radio', 'label' => 'Answer', 'options' => ['y' => 'Yes', 'n' => 'No']]],
    ]);

    expect(array_column($out['sections'][0]['fields'][0]['options'], 'value'))->toBe(['y', 'n']);
});

it('drops duplicate option values', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'radio', 'label' => 'A', 'options' => ['Yes', 'Yes', 'No']]],
    ]);

    expect($out['sections'][0]['fields'][0]['options'])->toHaveCount(2);
});

it('gives a choice field placeholder options rather than leaving it unusable', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'dropdown', 'label' => 'Empty']],
    ]);

    expect($out['sections'][0]['fields'][0]['options'])->toHaveCount(2);
});

it('strips options from a type that cannot have them', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'text', 'label' => 'A', 'options' => ['One', 'Two']]],
    ]);

    expect($out['sections'][0]['fields'][0]['options'])->toBe([]);
});

// ── Keys ─────────────────────────────────────────────────────────────────

it('derives a key from the label when none is given', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'text', 'label' => 'What is your Full Name?']],
    ]);

    expect($out['sections'][0]['fields'][0]['key'])->toBe('what_is_your_full_name');
});

it('suffixes colliding keys instead of overwriting', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [
            ['type' => 'text', 'label' => 'Name'],
            ['type' => 'text', 'label' => 'Name'],
            ['type' => 'text', 'label' => 'Name'],
        ],
    ]);

    expect(array_column($out['sections'][0]['fields'], 'key'))->toBe(['name', 'name_2', 'name_3']);
});

it('nulls the key on presentational fields', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'heading', 'label' => 'A heading'], ['type' => 'text', 'label' => 'B']],
    ]);

    expect($out['sections'][0]['fields'][0]['key'])->toBeNull();
});

it('invents a usable key when the label yields nothing', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'text', 'label' => '???']],
    ]);

    expect($out['sections'][0]['fields'][0]['key'])->toMatch('/^[a-z][a-z0-9_]*$/');
});

// ── Scalars and rules ────────────────────────────────────────────────────

it('coerces truthy strings to a real boolean for required', function ($given, bool $expected) {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'text', 'label' => 'A', 'required' => $given]],
    ]);

    expect($out['sections'][0]['fields'][0]['required'])->toBe($expected);
})->with([
    ['yes', true], ['true', true], ['1', true], ['Y', true], ['on', true],
    ['no', false], ['false', false], ['', false],
    [true, true], [false, false],
]);

it('parses a comma-separated mimes string with leading dots', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'file', 'label' => 'CV', 'rules' => ['accept' => '.pdf, .DOCX']]],
    ]);

    expect($out['sections'][0]['fields'][0]['validation']['mimes'])->toBe(['pdf', 'docx']);
});

it('extracts an extension from a full mime type', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'file', 'label' => 'CV', 'validation' => ['mimes' => ['application/pdf']]]],
    ]);

    expect($out['sections'][0]['fields'][0]['validation']['mimes'])->toBe(['pdf']);
});

it('maps aliased rule names onto the canonical ones', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'text', 'label' => 'A', 'rules' => [
            'minLength' => '3', 'maxLength' => '40', 'pattern' => '^[a-z]+$',
        ]]],
    ]);

    $v = $out['sections'][0]['fields'][0]['validation'];

    expect($v['min_length'])->toBe(3)
        ->and($v['max_length'])->toBe(40)
        ->and($v['regex'])->toBe('^[a-z]+$');
});

it('swaps an inverted range rather than failing on it', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'text', 'label' => 'A', 'validation' => ['min_length' => 50, 'max_length' => 10]]],
    ]);

    $v = $out['sections'][0]['fields'][0]['validation'];

    expect($v['min_length'])->toBe(10)->and($v['max_length'])->toBe(50);
});

it('discards a regex that will not compile', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'text', 'label' => 'A', 'validation' => ['regex' => '[unclosed']]],
    ]);

    expect($out['sections'][0]['fields'][0]['validation']['regex'])->toBeNull();
});

it('discards file rules applied to a non-file field', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'text', 'label' => 'A', 'validation' => ['max_kb' => 100, 'mimes' => ['pdf']]]],
    ]);

    $v = $out['sections'][0]['fields'][0]['validation'];

    expect($v['max_kb'])->toBeNull()->and($v['mimes'])->toBe([]);
});

it('drops an unsafe redirect URL', function () {
    $out = $this->normaliser->normalise([
        'title' => 'X',
        'settings' => ['redirect_url' => 'javascript:alert(1)'],
        'fields' => [['type' => 'text', 'label' => 'A']],
    ]);

    expect($out['settings']['redirect_url'])->toBeNull();
});

it('trims whitespace and clamps an overlong title', function () {
    $out = $this->normaliser->normalise(['title' => '  '.str_repeat('a', 300).'  ']);

    expect(mb_strlen($out['title']))->toBe(200);
});

// ── The contract that matters ────────────────────────────────────────────

it('turns realistic messy LLM output into a schema that validates', function () {
    $messy = [
        'title' => '  Job Application  ',
        'fields' => [
            ['type' => 'string', 'label' => 'Your Name'],
            ['type' => 'select', 'label' => 'Country', 'options' => ['India', 'United Kingdom']],
            ['type' => 'e-mail', 'name' => 'Email Address', 'required' => 'yes'],
            ['type' => 'multiselect', 'label' => 'Skills', 'options' => ['PHP', 'SQL']],
            ['type' => 'upload', 'label' => 'CV', 'rules' => ['accept' => '.pdf, .docx', 'max_size' => '4096']],
            ['type' => 'stars', 'label' => 'Rate us'],
            ['type' => 'title', 'label' => 'Extra info'],
            ['type' => 'text', 'label' => 'Your Name'],
        ],
    ];

    // This is the whole point of the class: one mechanical pass, no retry.
    expect($this->normaliser->normalise($messy))->toBeValidSchema();
});

it('is idempotent', function () {
    $once = $this->normaliser->normalise([
        'title' => 'X',
        'fields' => [['type' => 'select', 'label' => 'A', 'options' => ['One', 'Two']]],
    ]);

    // Ids are regenerated per run, so compare everything else. Normalising an
    // already-normal schema must not change its meaning.
    $twice = (new SchemaNormaliser)->normalise($once);

    $strip = function (array $s) {
        foreach ($s['sections'] as &$section) {
            unset($section['id']);
            foreach ($section['fields'] as &$field) {
                unset($field['id']);
            }
        }

        return $s;
    };

    expect($strip($twice))->toBe($strip($once));
});
