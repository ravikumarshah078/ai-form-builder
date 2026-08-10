<?php

use App\Enums\FieldType;
use App\Forms\FieldFactory;
use App\Forms\RuleCompiler;
use Illuminate\Support\Facades\Validator;

/**
 * RuleCompiler is the "never trust the browser" boundary.
 *
 * Half of these tests assert the compiled rule array, which is fast and
 * precise. The other half push real payloads through Laravel's Validator,
 * because a rule array that looks right but does not actually reject bad input
 * is worse than no test at all.
 */

beforeEach(function () {
    $this->compiler = new RuleCompiler;
});

function compiledFor(array $fields): array
{
    return (new RuleCompiler)->compile(schemaWith($fields));
}

// ── Rule shape ───────────────────────────────────────────────────────────

it('marks a required field required and an optional one nullable', function () {
    $c = compiledFor([
        FieldFactory::make(FieldType::Text, ['key' => 'a', 'required' => true]),
        FieldFactory::make(FieldType::Text, ['key' => 'b', 'required' => false]),
    ]);

    expect($c['rules']['a'][0])->toBe('required')
        ->and($c['rules']['b'][0])->toBe('nullable');
});

it('applies the intrinsic format rule for the type', function (string $type, string $expected) {
    $c = compiledFor([FieldFactory::make(FieldType::from($type), ['key' => 'a'])]);

    expect($c['rules']['a'])->toContain($expected);
})->with([
    ['email', 'email:rfc'],
    ['url', 'url'],
    ['number', 'numeric'],
    ['date', 'date'],
    ['file', 'file'],
    ['checkbox', 'array'],
]);

it('always applies a length ceiling even when the author set none', function () {
    $c = compiledFor([FieldFactory::make(FieldType::Text, ['key' => 'a'])]);

    // Without this, one request can push megabytes into a JSON column.
    expect($c['rules']['a'])->toContain('max:5000');
});

it('produces no rule for a presentational field', function () {
    $c = compiledFor([
        FieldFactory::make(FieldType::Heading, ['label' => 'Just a heading']),
        FieldFactory::make(FieldType::Text, ['key' => 'real']),
    ]);

    expect($c['rules'])->toHaveKey('real')->and($c['rules'])->toHaveCount(1);
});

it('constrains checkbox members to the declared option values', function () {
    $c = compiledFor([FieldFactory::make(FieldType::Checkbox, [
        'key' => 'skills',
        'options' => [['value' => 'php', 'label' => 'PHP'], ['value' => 'sql', 'label' => 'SQL']],
    ])]);

    expect($c['rules']['skills.*'])->toBe(['in:php,sql']);
});

it('requires at least one box on a required checkbox group', function () {
    $c = compiledFor([FieldFactory::make(FieldType::Checkbox, ['key' => 's', 'required' => true])]);

    // 'required' alone does not reject an empty array.
    expect($c['rules']['s'])->toContain('min:1');
});

it('compiles file mimes and size limits', function () {
    $c = compiledFor([FieldFactory::make(FieldType::File, ['key' => 'cv'])]);

    expect($c['rules']['cv'])->toContain('mimes:pdf,doc,docx,png,jpg')
        ->and($c['rules']['cv'])->toContain('max:5120');
});

it('wraps a stored regex in delimiters at compile time', function () {
    $c = compiledFor([FieldFactory::make(FieldType::Text, [
        'key' => 'code',
        'validation' => array_replace(
            FieldFactory::defaultValidation(FieldType::Text),
            ['regex' => '^[A-Z]{3}$']
        ),
    ])]);

    // Stored undelimited so a form author cannot append modifiers such as /e.
    expect($c['rules']['code'])->toContain('regex:/^[A-Z]{3}$/');
});

it('escapes a forward slash inside a stored regex', function () {
    $c = compiledFor([FieldFactory::make(FieldType::Text, [
        'key' => 'path',
        'validation' => array_replace(
            FieldFactory::defaultValidation(FieldType::Text),
            ['regex' => '^a/b$']
        ),
    ])]);

    expect($c['rules']['path'])->toContain('regex:/^a\/b$/');
});

it('exposes labels as attributes so messages read naturally', function () {
    $c = compiledFor([FieldFactory::make(FieldType::Text, ['key' => 'full_name', 'label' => 'Full name'])]);

    expect($c['attributes']['full_name'])->toBe('Full name');
});

it('lists answer keys in display order and skips presentational fields', function () {
    $keys = $this->compiler->answerKeys(schemaWith([
        FieldFactory::make(FieldType::Text, ['key' => 'one']),
        FieldFactory::make(FieldType::Heading, ['label' => 'H']),
        FieldFactory::make(FieldType::Text, ['key' => 'two']),
    ]));

    expect($keys)->toBe(['one', 'two']);
});

// ── Live enforcement ─────────────────────────────────────────────────────

function validateAgainst(array $fields, array $payload)
{
    $c = compiledFor($fields);

    return Validator::make($payload, $c['rules'], $c['messages'], $c['attributes']);
}

it('rejects a value shorter than min_length', function () {
    $v = validateAgainst([
        FieldFactory::make(FieldType::Text, [
            'key' => 'name',
            'required' => true,
            'validation' => array_replace(
                FieldFactory::defaultValidation(FieldType::Text),
                ['min_length' => 3]
            ),
        ]),
    ], ['name' => 'ab']);

    expect($v->fails())->toBeTrue()->and($v->errors()->keys())->toContain('name');
});

it('rejects a choice that is not in the option list', function () {
    $v = validateAgainst([
        FieldFactory::make(FieldType::Dropdown, [
            'key' => 'country',
            'options' => [['value' => 'in', 'label' => 'India']],
        ]),
    ], ['country' => 'atlantis']);

    expect($v->fails())->toBeTrue();
});

it('rejects a checkbox member that is not in the option list', function () {
    $v = validateAgainst([
        FieldFactory::make(FieldType::Checkbox, [
            'key' => 'skills',
            'options' => [['value' => 'php', 'label' => 'PHP']],
        ]),
    ], ['skills' => ['php', 'cobol']]);

    expect($v->fails())->toBeTrue()
        ->and($v->errors()->keys())->toContain('skills.1');
});

it('rejects a rating outside its bounds', function () {
    $v = validateAgainst([FieldFactory::make(FieldType::Rating, ['key' => 'score'])], ['score' => 9]);

    expect($v->fails())->toBeTrue();
});

it('rejects a malformed email', function () {
    $v = validateAgainst([FieldFactory::make(FieldType::Email, ['key' => 'e'])], ['e' => 'not-an-email']);

    expect($v->fails())->toBeTrue();
});

it('accepts a fully valid payload', function () {
    $v = validateAgainst([
        FieldFactory::make(FieldType::Text, ['key' => 'name', 'required' => true]),
        FieldFactory::make(FieldType::Email, ['key' => 'email']),
        FieldFactory::make(FieldType::Checkbox, [
            'key' => 'skills',
            'options' => [['value' => 'php', 'label' => 'PHP']],
        ]),
        FieldFactory::make(FieldType::Rating, ['key' => 'score']),
    ], [
        'name' => 'Priya Sharma',
        'email' => 'priya@example.com',
        'skills' => ['php'],
        'score' => 4,
    ]);

    expect($v->passes())->toBeTrue(implode(' | ', $v->errors()->all()));
});

it('lets an optional field be omitted entirely', function () {
    $v = validateAgainst([FieldFactory::make(FieldType::Text, ['key' => 'optional'])], []);

    expect($v->passes())->toBeTrue();
});

it('does not leak the regex pattern in the failure message', function () {
    $v = validateAgainst([
        FieldFactory::make(FieldType::Text, [
            'key' => 'code',
            'validation' => array_replace(
                FieldFactory::defaultValidation(FieldType::Text),
                ['regex' => '^SECRET[0-9]{4}$']
            ),
        ]),
    ], ['code' => 'nope']);

    expect($v->fails())->toBeTrue()
        ->and(implode(' ', $v->errors()->all()))->not->toContain('SECRET');
});
