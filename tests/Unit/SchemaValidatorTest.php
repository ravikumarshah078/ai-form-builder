<?php

use App\Enums\FieldType;
use App\Forms\FieldFactory;
use App\Forms\SchemaValidator;

/**
 * SchemaValidator is the single gate every schema passes through, whether it
 * came from the canvas, an LLM or a Word document. A hole here is a hole in
 * all three, so it is tested harder than anything else in the codebase.
 */

it('accepts a well-formed schema', function () {
    expect(schemaWith([
        FieldFactory::make(FieldType::Text, ['label' => 'Full name', 'key' => 'full_name', 'required' => true]),
        FieldFactory::make(FieldType::Email, ['label' => 'Email', 'key' => 'email']),
        FieldFactory::make(FieldType::Checkbox, ['label' => 'Skills', 'key' => 'skills']),
        FieldFactory::make(FieldType::Heading, ['label' => 'A heading']),
    ]))->toBeValidSchema();
});

it('accepts a draft with no sections yet', function () {
    expect(FieldFactory::emptySchema('Brand new form'))->toBeValidSchema();
});

it('rejects a non-object schema', function () {
    expect((new SchemaValidator)->validate('not a schema')->fails())->toBeTrue();
    expect((new SchemaValidator)->validate(null)->fails())->toBeTrue();
});

// ── The hallucination gate ───────────────────────────────────────────────

it('rejects a field type that is not in the FieldType enum', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Text)]);
    $schema['sections'][0]['fields'][0]['type'] = 'signature_pad';

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.type');
});

it('names the offending type and lists the legal ones', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Text)]);
    $schema['sections'][0]['fields'][0]['type'] = 'signature_pad';

    $message = (new SchemaValidator)->validate($schema)->first();

    // The AI repair loop feeds this string back to the model, so it has to
    // contain both what was wrong and what is allowed.
    expect($message)->toContain('signature_pad')
        ->and($message)->toContain('dropdown');
});

// ── Keys ─────────────────────────────────────────────────────────────────

it('rejects duplicate keys across different sections', function () {
    $schema = FieldFactory::emptySchema('Two sections');

    $schema['sections'][] = FieldFactory::makeSection([
        'fields' => [FieldFactory::make(FieldType::Phone, ['key' => 'phone'])],
    ]);
    $schema['sections'][] = FieldFactory::makeSection([
        'fields' => [FieldFactory::make(FieldType::Text, ['key' => 'phone'])],
    ]);

    // Without this check the two would silently overwrite each other in the
    // submission JSON.
    expect($schema)->toFailSchemaValidation('sections.1.fields.0.key');
});

it('rejects keys that are not snake_case identifiers', function (string $key) {
    $schema = schemaWith([FieldFactory::make(FieldType::Text, ['key' => $key])]);

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.key');
})->with(['Full-Name', 'full name', '1st_choice', 'FULL_NAME', 'full.name', '']);

it('requires a key on a field that collects an answer', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Text)]);
    $schema['sections'][0]['fields'][0]['key'] = null;

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.key');
});

it('forbids a key on a presentational field', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Divider)]);
    $schema['sections'][0]['fields'][0]['key'] = 'divider_one';

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.key');
});

// ── Options ──────────────────────────────────────────────────────────────

it('rejects options on a field type that does not take them', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Text, [
        'options' => [['value' => 'a', 'label' => 'A']],
    ])]);

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.options');
});

it('rejects a choice field with no options', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Dropdown, ['options' => []])]);

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.options');
});

it('rejects duplicate option values within a field', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Radio, ['options' => [
        ['value' => 'yes', 'label' => 'Yes'],
        ['value' => 'yes', 'label' => 'Yeah'],
    ]])]);

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.options.1.value');
});

it('requires a label on every option', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Radio, ['options' => [
        ['value' => 'yes', 'label' => ''],
    ]])]);

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.options.0.label');
});

// ── Validation rules ─────────────────────────────────────────────────────

it('rejects a range whose floor is above its ceiling', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Text, [
        'validation' => array_replace(
            FieldFactory::defaultValidation(FieldType::Text),
            ['min_length' => 50, 'max_length' => 10]
        ),
    ])]);

    // Such a field can never be satisfied, and the respondent would never
    // learn why.
    expect($schema)->toFailSchemaValidation('sections.0.fields.0.validation.min_length');
});

it('rejects a regex that will not compile', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Text, [
        'validation' => array_replace(
            FieldFactory::defaultValidation(FieldType::Text),
            ['regex' => '[unclosed']
        ),
    ])]);

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.validation.regex');
});

it('rejects an unknown validation rule', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Text, [
        'validation' => array_replace(
            FieldFactory::defaultValidation(FieldType::Text),
            ['drop_table' => true]
        ),
    ])]);

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.validation.drop_table');
});

it('rejects an unsupported upload extension', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::File, [
        'validation' => array_replace(
            FieldFactory::defaultValidation(FieldType::File),
            ['mimes' => ['exe']]
        ),
    ])]);

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.validation.mimes');
});

it('rejects file rules on a non-file field', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Text, [
        'validation' => array_replace(
            FieldFactory::defaultValidation(FieldType::Text),
            ['max_kb' => 100]
        ),
    ])]);

    expect($schema)->toFailSchemaValidation('sections.0.fields.0.validation');
});

// ── Settings ─────────────────────────────────────────────────────────────

it('rejects a redirect URL that is not http or https', function (string $url) {
    expect(schemaWith([FieldFactory::make(FieldType::Text)], ['redirect_url' => $url]))
        ->toFailSchemaValidation('settings.redirect_url');
})->with([
    'javascript:alert(document.cookie)',
    'data:text/html;base64,PHNjcmlwdD4=',
    'not-a-url',
]);

it('accepts a normal https redirect URL', function () {
    expect(schemaWith(
        [FieldFactory::make(FieldType::Text)],
        ['redirect_url' => 'https://example.com/thanks']
    ))->toBeValidSchema();
});

// ── Structure ────────────────────────────────────────────────────────────

it('requires a title', function () {
    $schema = schemaWith([FieldFactory::make(FieldType::Text)]);
    $schema['title'] = '   ';

    expect($schema)->toFailSchemaValidation('title');
});

it('rejects duplicate field ids', function () {
    $field = FieldFactory::make(FieldType::Text, ['key' => 'a']);
    $second = array_replace(FieldFactory::make(FieldType::Text, ['key' => 'b']), ['id' => $field['id']]);

    expect(schemaWith([$field, $second]))->toFailSchemaValidation('sections.0.fields.1.id');
});

it('rejects a form whose only fields collect nothing', function () {
    expect(schemaWith([
        FieldFactory::make(FieldType::Heading, ['label' => 'Just a heading']),
        FieldFactory::make(FieldType::Divider),
    ]))->toFailSchemaValidation('sections');
});

it('reports every problem at once rather than stopping at the first', function () {
    $schema = schemaWith([
        FieldFactory::make(FieldType::Text, ['key' => 'Bad-Key']),
        FieldFactory::make(FieldType::Dropdown, ['options' => []]),
    ]);
    $schema['title'] = '';

    // The raw JSON editor shows all failures at once; a fail-fast validator
    // would make fixing a broken paste a game of whack-a-mole.
    expect((new SchemaValidator)->validate($schema)->count())->toBeGreaterThanOrEqual(3);
});
