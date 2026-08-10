<?php

use App\Enums\FieldType;
use App\Forms\FieldFactory;
use App\Forms\SchemaNormaliser;
use App\Forms\SchemaReconciler;

/**
 * Field identity must survive an AI edit.
 *
 * These tests exist because of a real failure. Asked to add a section, Gemini
 * returned a valid form with the `key` dropped from fields it had kept; the
 * normaliser then derived new keys from the labels, and 4 of 9 keys changed.
 * Every answer already collected under those keys would have been orphaned.
 *
 * The prompt tells the model to preserve keys. It did not. So this is enforced
 * in code instead.
 */

beforeEach(function () {
    $this->reconciler = new SchemaReconciler;
    $this->normaliser = new SchemaNormaliser;
});

function originalSchema(): array
{
    $schema = FieldFactory::emptySchema('Internship Application');

    $schema['sections'][] = FieldFactory::makeSection([
        'title' => 'Personal details',
        'fields' => [
            FieldFactory::make(FieldType::Text, ['key' => 'full_name', 'label' => 'Full name']),
            FieldFactory::make(FieldType::Email, ['key' => 'email', 'label' => 'Email address']),
            FieldFactory::make(FieldType::Phone, ['key' => 'phone', 'label' => 'Phone number']),
        ],
    ]);

    return $schema;
}

it('restores a key the model dropped', function () {
    $original = originalSchema();

    // Exactly what Gemini returned: same labels, no keys.
    $generated = $this->normaliser->normalise([
        'title' => 'Internship Application',
        'sections' => [[
            'title' => 'Personal details',
            'fields' => [
                ['type' => 'text', 'label' => 'Full name'],
                ['type' => 'email', 'label' => 'Email address'],
                ['type' => 'phone', 'label' => 'Phone number'],
            ],
        ]],
    ]);

    // Without reconciliation the normaliser derives these from the labels.
    expect(array_column($generated['sections'][0]['fields'], 'key'))
        ->toBe(['full_name', 'email_address', 'phone_number']);

    $fixed = $this->reconciler->reconcile($original, $generated);

    expect(array_column($fixed['sections'][0]['fields'], 'key'))
        ->toBe(['full_name', 'email', 'phone']);
});

it('restores the original field ids too', function () {
    $original = originalSchema();
    $originalIds = array_column($original['sections'][0]['fields'], 'id');

    $generated = $this->normaliser->normalise([
        'title' => 'Internship Application',
        'sections' => [[
            'title' => 'Personal details',
            'fields' => [
                ['type' => 'text', 'label' => 'Full name'],
                ['type' => 'email', 'label' => 'Email address'],
                ['type' => 'phone', 'label' => 'Phone number'],
            ],
        ]],
    ]);

    $fixed = $this->reconciler->reconcile($original, $generated);

    expect(array_column($fixed['sections'][0]['fields'], 'id'))->toBe($originalIds);
});

it('leaves genuinely new fields alone', function () {
    $original = originalSchema();

    $generated = $this->normaliser->normalise([
        'title' => 'Internship Application',
        'sections' => [
            [
                'title' => 'Personal details',
                'fields' => [
                    ['type' => 'text', 'label' => 'Full name'],
                    ['type' => 'email', 'label' => 'Email address'],
                    ['type' => 'phone', 'label' => 'Phone number'],
                ],
            ],
            [
                'title' => 'Emergency contact',
                'fields' => [
                    ['type' => 'text', 'label' => 'Contact name'],
                    ['type' => 'phone', 'label' => 'Contact phone'],
                ],
            ],
        ],
    ]);

    $fixed = $this->reconciler->reconcile($original, $generated);

    expect(array_column($fixed['sections'][0]['fields'], 'key'))->toBe(['full_name', 'email', 'phone'])
        ->and(array_column($fixed['sections'][1]['fields'], 'key'))->toBe(['contact_name', 'contact_phone']);
});

it('matches on an echoed id even when the label changed', function () {
    $original = originalSchema();
    $emailId = $original['sections'][0]['fields'][1]['id'];

    $generated = $this->normaliser->normalise([
        'title' => 'X',
        'sections' => [[
            'title' => 'Personal details',
            'fields' => [
                ['id' => $emailId, 'type' => 'email', 'label' => 'Work e-mail (translated)'],
            ],
        ]],
    ]);

    $fixed = $this->reconciler->reconcile($original, $generated);

    // This is what makes "translate all labels to Hindi" safe: the labels all
    // change, but the ids come back and the keys survive.
    expect($fixed['sections'][0]['fields'][0]['key'])->toBe('email');
});

it('matches on an echoed key even when the label changed', function () {
    $original = originalSchema();

    $generated = $this->normaliser->normalise([
        'title' => 'X',
        'sections' => [[
            'title' => 'S',
            'fields' => [['type' => 'phone', 'label' => 'फ़ोन नंबर', 'key' => 'phone']],
        ]],
    ]);

    $fixed = $this->reconciler->reconcile($original, $generated);

    expect($fixed['sections'][0]['fields'][0]['key'])->toBe('phone')
        ->and($fixed['sections'][0]['fields'][0]['label'])->toBe('फ़ोन नंबर');
});

it('ignores punctuation and case when matching labels', function () {
    $original = originalSchema();

    $generated = $this->normaliser->normalise([
        'title' => 'X',
        'sections' => [[
            'title' => 'S',
            'fields' => [['type' => 'email', 'label' => '  EMAIL ADDRESS:  ']],
        ]],
    ]);

    $fixed = $this->reconciler->reconcile($original, $generated);

    expect($fixed['sections'][0]['fields'][0]['key'])->toBe('email');
});

it('does not assign the same original field to two generated fields', function () {
    $original = originalSchema();

    $generated = $this->normaliser->normalise([
        'title' => 'X',
        'sections' => [[
            'title' => 'S',
            'fields' => [
                ['type' => 'email', 'label' => 'Email address'],
                ['type' => 'email', 'label' => 'Email address'],   // duplicated
            ],
        ]],
    ]);

    $fixed = $this->reconciler->reconcile($original, $generated);
    $keys = array_column($fixed['sections'][0]['fields'], 'key');

    expect($keys[0])->toBe('email')
        ->and($keys[1])->not->toBe('email')
        ->and($keys)->toBe(array_unique($keys));
});

it('produces a schema that still validates', function () {
    $original = originalSchema();

    $generated = $this->normaliser->normalise([
        'title' => 'X',
        'sections' => [[
            'title' => 'S',
            'fields' => [
                ['type' => 'text', 'label' => 'Full name'],
                ['type' => 'email', 'label' => 'Email address'],
                ['type' => 'text', 'label' => 'Brand new field'],
            ],
        ]],
    ]);

    expect($this->reconciler->reconcile($original, $generated))->toBeValidSchema();
});

it('is a no-op when there is nothing to reconcile against', function () {
    $generated = $this->normaliser->normalise([
        'title' => 'X',
        'sections' => [['title' => 'S', 'fields' => [['type' => 'text', 'label' => 'A']]]],
    ]);

    expect($this->reconciler->reconcile([], $generated))->toBe($generated);
});
