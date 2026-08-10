<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case bindings
|--------------------------------------------------------------------------
|
| Feature tests get the full application and a transactional database.
|
| Unit tests get the application but NOT the database. The schema layer itself
| has no framework dependency, but proving that a compiled rule set actually
| rejects bad input means running Laravel's Validator against it — and a rule
| array that looks correct while failing to enforce anything is precisely the
| bug worth catching. Booting the container costs nothing; no connection is
| opened unless a test asks for one.
|
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Integration tests use TRUNCATION rather than a transaction.
|
| RefreshDatabase wraps each test in a transaction and rolls it back, which is
| fast and normally invisible. It is not invisible to MySQL FULLTEXT: InnoDB
| does not update a FULLTEXT index until the writing transaction commits, so
| MATCH ... AGAINST finds nothing a test just inserted. Submission search would
| fail here while working perfectly in production — the worst kind of test.
|
| Truncation commits, so anything depending on a real committed index goes in
| tests/Integration.
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Custom expectations
|--------------------------------------------------------------------------
*/

/**
 * Assert a schema passes SchemaValidator, printing the failures if it does not.
 *
 * Without this, a failing schema assertion reads "false is not true", which
 * tells you nothing about which of thirty rules tripped.
 */
expect()->extend('toBeValidSchema', function () {
    $result = (new App\Forms\SchemaValidator)->validate($this->value);

    // PHPUnit's Assert is used directly rather than a nested expect(), because
    // Pest expectations take extra arguments as more values to check, not as a
    // failure message.
    PHPUnit\Framework\Assert::assertTrue(
        $result->passes(),
        'Expected a valid schema. Errors: '.implode(' | ', $result->messages())
    );

    return $this;
});

/**
 * Assert a schema fails, optionally at a specific dot-path.
 */
expect()->extend('toFailSchemaValidation', function (?string $atPath = null) {
    $result = (new App\Forms\SchemaValidator)->validate($this->value);

    PHPUnit\Framework\Assert::assertTrue(
        $result->fails(),
        'Expected the schema to be invalid, but it passed.'
    );

    if ($atPath !== null) {
        PHPUnit\Framework\Assert::assertContains(
            $atPath,
            array_keys($result->errors()),
            'Expected an error at "'.$atPath.'". Errors were at: '
                .implode(', ', array_keys($result->errors()))
        );
    }

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A minimal valid schema, with fields appended.
 *
 * @param  array<int, array<string, mixed>>  $fields
 * @return array<string, mixed>
 */
function schemaWith(array $fields, array $settings = []): array
{
    $schema = App\Forms\FieldFactory::emptySchema('Test form');

    $schema['settings'] = array_replace($schema['settings'], $settings);

    $schema['sections'][] = App\Forms\FieldFactory::makeSection([
        'title' => 'Section one',
        'fields' => $fields,
    ]);

    return $schema;
}
