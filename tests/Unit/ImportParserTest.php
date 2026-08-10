<?php

use App\Services\Import\DocxParser;
use App\Services\Import\ImportException;
use App\Services\Import\ParseResult;
use App\Services\Import\XlsxParser;

/**
 * The deterministic half of Part C, run against the committed sample files.
 *
 * The brief warns that reviewers will test with their own documents, so the
 * defensive cases at the bottom matter as much as the happy path: a corrupt
 * file, an empty sheet and a text file renamed .docx must all produce a clear
 * message rather than a stack trace.
 */

function samplePath(string $file): string
{
    return base_path('database/samples/'.$file);
}

beforeEach(function () {
    if (! is_file(samplePath('internship-application.docx'))) {
        $this->markTestSkipped('Run `php artisan import:samples` first.');
    }
});

// ── Word ─────────────────────────────────────────────────────────────────

it('maps Word headings to sections and questions to fields', function () {
    $result = (new DocxParser)->parse(samplePath('internship-application.docx'));

    $titles = array_column($result->schema['sections'], 'title');

    expect($titles)->toContain('Personal Details')
        ->and($titles)->toContain('Education History')
        ->and($titles)->toContain('Attachments')
        ->and($result->fieldCount())->toBe(12);
});

it('produces a schema that passes validation', function () {
    expect((new DocxParser)->parse(samplePath('internship-application.docx'))->schema)
        ->toBeValidSchema();
});

it('infers specific types from question wording', function () {
    $result = (new DocxParser)->parse(samplePath('internship-application.docx'));

    $byLabel = [];

    foreach ($result->schema['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            $byLabel[$field['label']] = $field['type'];
        }
    }

    expect($byLabel['What is your email address?'])->toBe('email')
        ->and($byLabel['Phone number'])->toBe('phone')
        ->and($byLabel['Date of birth'])->toBe('date')
        ->and($byLabel['Please attach your resume'])->toBe('file')
        ->and($byLabel['Describe your most relevant project'])->toBe('textarea')
        ->and($byLabel['How many years of experience do you have?'])->toBe('number')
        ->and($byLabel['Rate your confidence with backend development out of 5'])->toBe('rating');
});

it('turns a bullet list into the previous question\'s options', function () {
    $result = (new DocxParser)->parse(samplePath('internship-application.docx'));

    $qualification = null;

    foreach ($result->schema['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            if ($field['label'] === 'Highest qualification') {
                $qualification = $field;
            }
        }
    }

    // PhpWord's reader returns ListItemRun, not ListItem. Handling only the
    // latter turned each of these options into its own text field.
    expect($qualification)->not->toBeNull()
        ->and($qualification['type'])->toBe('radio')
        ->and(array_column($qualification['options'], 'label'))
        ->toBe(['B.Sc.', 'B.Tech.', 'BCA', 'Other']);
});

it('reads a checkbox list as a multi-select', function () {
    $result = (new DocxParser)->parse(samplePath('internship-application.docx'));

    $skills = null;

    foreach ($result->schema['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            if (str_starts_with($field['label'], 'Which of these')) {
                $skills = $field;
            }
        }
    }

    expect($skills['type'])->toBe('checkbox')
        // The ☐ glyphs are stripped from the labels.
        ->and(array_column($skills['options'], 'label'))
        ->toBe(['PHP', 'Laravel', 'MySQL', 'JavaScript', 'Python']);
});

it('marks a starred question as required', function () {
    $result = (new DocxParser)->parse(samplePath('internship-application.docx'));

    $field = $result->schema['sections'][0]['fields'][0];

    expect($field['label'])->toBe('Full name')
        // "*" is stripped from the label but recorded as required.
        ->and($field['required'])->toBeTrue();
});

it('copes with a document that never used heading styles', function () {
    $result = (new DocxParser)->parse(samplePath('messy-questionnaire.docx'));

    expect($result->schema)->toBeValidSchema()
        // "CUSTOMER FEEDBACK" is a shouted title, not the first question.
        ->and($result->schema['title'])->toBe('Customer Feedback')
        ->and($result->fieldCount())->toBeGreaterThan(4);
});

it('reads a two-column table as label and answer', function () {
    $result = (new DocxParser)->parse(samplePath('messy-questionnaire.docx'));

    $labels = [];

    foreach ($result->schema['sections'] as $section) {
        $labels = array_merge($labels, array_column($section['fields'], 'label'));
    }

    expect($labels)->toContain('Your name')->and($labels)->toContain('Contact email');
});

it('reports an orphan list rather than dropping it silently', function () {
    $result = (new DocxParser)->parse(samplePath('messy-questionnaire.docx'));

    expect($result->warnings)->not->toBeEmpty();
});

// ── Excel: Layout A, definition sheet ────────────────────────────────────

it('reads a definition sheet and honours its declared types', function () {
    $result = (new XlsxParser)->parse(samplePath('field-definitions.xlsx'));

    expect($result->layout)->toBe('xlsx-definition')
        ->and($result->schema)->toBeValidSchema()
        ->and($result->sectionCount())->toBe(4)
        ->and($result->fieldCount())->toBe(10);
});

it('needs no AI at all for a definition sheet', function () {
    $result = (new XlsxParser)->parse(samplePath('field-definitions.xlsx'));

    // Every type is declared, so nothing is ambiguous and the import costs
    // nothing. This is the point of doing the deterministic pass first.
    expect($result->uncertainFieldIds())->toBe([]);
});

it('splits pipe-delimited options', function () {
    $result = (new XlsxParser)->parse(samplePath('field-definitions.xlsx'));

    $sizes = null;

    foreach ($result->schema['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            if ($field['label'] === 'T-shirt size') {
                $sizes = $field;
            }
        }
    }

    expect(array_column($sizes['options'], 'label'))->toBe(['XS', 'S', 'M', 'L', 'XL', 'XXL']);
});

it('falls back to guessing when a declared type is unrecognised', function () {
    $result = (new XlsxParser)->parse(samplePath('field-definitions.xlsx'));

    $dietary = null;

    foreach ($result->schema['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            if ($field['label'] === 'Dietary requirements') {
                $dietary = $field;
            }
        }
    }

    // The sheet says "multiline", which the normaliser's alias table resolves.
    expect($dietary['type'])->toBe('textarea');
});

it('reports a row that has data but no label', function () {
    $result = (new XlsxParser)->parse(samplePath('field-definitions.xlsx'));

    expect(collect($result->warnings)->pluck('type'))->toContain('row_without_label');
});

// ── Excel: Layout B, plain header row ────────────────────────────────────

it('reads a plain data sheet using row 2 as evidence', function () {
    $result = (new XlsxParser)->parse(samplePath('attendee-list.xlsx'));

    $types = [];

    foreach ($result->schema['sections'][0]['fields'] as $field) {
        $types[$field['label']] = $field['type'];
    }

    expect($result->layout)->toBe('xlsx-header-row')
        ->and($types['Contact'])->toBe('email')
        // "2024-06-01" also satisfies a naive phone pattern, so date is checked
        // first and the phone rule additionally requires seven digits.
        ->and($types['Joined On'])->toBe('date')
        ->and($types['Years'])->toBe('number');
});

it('detects a choice column by repetition, not by a small value count', function () {
    $result = (new XlsxParser)->parse(samplePath('attendee-list.xlsx'));

    $types = [];

    foreach ($result->schema['sections'][0]['fields'] as $field) {
        $types[$field['label']] = $field['type'];
    }

    // Department repeats across rows, so it is a genuine choice list.
    expect($types['Department'])->toBe('dropdown')
        // Full Name has one distinct value per row: that is free text, and an
        // earlier version of this made every such column a dropdown.
        ->and($types['Full Name'])->not->toBe('dropdown')
        ->and($types['Contact'])->not->toBe('dropdown');
});

// ── Defensive: reviewers will use their own files ───────────────────────

it('gives a readable error for a file that is not really a docx', function () {
    $path = tempnam(sys_get_temp_dir(), 'fake').'.docx';
    file_put_contents($path, 'this is plain text, not a Word document');

    expect(fn () => (new DocxParser)->parse($path))
        ->toThrow(ImportException::class);

    unlink($path);
});

it('gives a readable error for a file that is not really a spreadsheet', function () {
    $path = tempnam(sys_get_temp_dir(), 'fake').'.xlsx';
    file_put_contents($path, 'not a spreadsheet');

    expect(fn () => (new XlsxParser)->parse($path))
        ->toThrow(ImportException::class);

    unlink($path);
});

it('gives a readable error for an empty spreadsheet', function () {
    $book = new PhpOffice\PhpSpreadsheet\Spreadsheet;
    $path = tempnam(sys_get_temp_dir(), 'empty').'.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

    expect(fn () => (new XlsxParser)->parse($path))
        ->toThrow(ImportException::class);

    unlink($path);
});

it('never throws a raw exception at the caller', function (string $file) {
    // Whatever happens, it is an ImportException with a message written for a
    // human — never a TypeError or a library exception.
    try {
        (new DocxParser)->parse(samplePath($file));
        expect(true)->toBeTrue();
    } catch (ImportException $e) {
        expect($e->getMessage())->not->toBeEmpty();
    }
})->with(['internship-application.docx', 'messy-questionnaire.docx']);

it('records a confidence and a reason for every detected field', function () {
    $result = (new DocxParser)->parse(samplePath('internship-application.docx'));

    expect($result->detections)->toHaveCount($result->fieldCount());

    foreach ($result->detections as $detection) {
        expect($detection['confidence'])->toBeIn([ParseResult::HIGH, ParseResult::LOW])
            ->and($detection['reason'])->not->toBeEmpty()
            ->and($detection['source'])->toBe('parser');
    }
});
