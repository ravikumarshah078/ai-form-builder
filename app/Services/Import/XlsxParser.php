<?php

namespace App\Services\Import;

use App\Enums\FieldType;
use App\Forms\FieldFactory;
use App\Forms\SchemaNormaliser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Turns an .xlsx into a form schema, deterministically.
 *
 * TWO SUPPORTED LAYOUTS, auto-detected from the header row. The brief asks for
 * "at least one clearly documented layout, and ideally a plain header-row sheet
 * too", so both are implemented and both are documented in the README.
 *
 * ── Layout A: field definition sheet ────────────────────────────────────
 * One row per field, with named columns. The header must contain at least two
 * of: Label/Question, Type, Required, Options.
 *
 *   | Section  | Label         | Type     | Required | Options      | Help |
 *   |----------|---------------|----------|----------|--------------|------|
 *   | Personal | Full name     | text     | yes      |              |      |
 *   | Personal | Degree        | dropdown | no       | BSc|BTech|BCA |     |
 *
 * Everything here is explicit, so every field is HIGH confidence and the AI
 * step is skipped entirely.
 *
 * ── Layout B: plain header row ──────────────────────────────────────────
 * A normal data sheet. Row 1 holds column names, and each becomes a field.
 * Row 2, if present, is used as a sample value to sharpen the type guess —
 * a cell containing "jane@example.com" settles it far better than the column
 * name "Contact" ever could.
 *
 *   | Full name    | Contact          | Joined     |
 *   |--------------|------------------|------------|
 *   | Priya Sharma | priya@example.com| 2024-06-01 |
 */
class XlsxParser
{
    /** Header names that identify Layout A, normalised to lower case. */
    private const DEFINITION_HEADERS = [
        'label' => 'label', 'question' => 'label', 'field' => 'label', 'field name' => 'label',
        'type' => 'type', 'field type' => 'type', 'input type' => 'type',
        'required' => 'required', 'mandatory' => 'required', 'is required' => 'required',
        'options' => 'options', 'choices' => 'options', 'values' => 'options',
        'section' => 'section', 'group' => 'section', 'category' => 'section',
        'help' => 'help', 'help text' => 'help', 'description' => 'help', 'hint' => 'help',
        'placeholder' => 'placeholder', 'example' => 'placeholder',
    ];

    /** Guard against a sheet with a runaway number of columns or rows. */
    private const MAX_COLUMNS = 200;

    private const MAX_ROWS = 500;

    /** @var array<int, array{type: string, message: string, excerpt: string|null}> */
    private array $warnings = [];

    /** @var array<string, array{confidence: string, reason: string, source: string}> */
    private array $detections = [];

    public function __construct(
        private readonly FieldTypeGuesser $guesser = new FieldTypeGuesser,
        private readonly SchemaNormaliser $normaliser = new SchemaNormaliser,
    ) {}

    public function parse(string $path, ?string $fallbackTitle = null): ParseResult
    {
        $this->warnings = [];
        $this->detections = [];

        try {
            // Xlsx explicitly, NOT createReaderForFile.
            //
            // createReaderForFile sniffs the content and happily falls back to
            // the CSV reader, so a text file renamed to .xlsx was being
            // "imported" into a nonsense one-column form instead of being
            // rejected. Uploads are restricted to .xlsx, so the reader should
            // be too.
            $reader = IOFactory::createReader('Xlsx');

            // Formatting and formulas are irrelevant here and reading them on a
            // large sheet is slow, so only values are loaded.
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (Throwable $e) {
            throw new ImportException(
                'That file could not be opened as a spreadsheet. It may be corrupted '
                .'or in a format we do not support.'
            );
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows = $this->readRows($sheet);

        if ($rows === []) {
            throw new ImportException('The first sheet is empty.');
        }

        $header = array_shift($rows);
        $mapping = $this->mapHeader($header);

        // Two named columns is enough to be sure; one could be coincidence.
        $isDefinition = count(array_unique(array_values($mapping))) >= 2
            && in_array('label', $mapping, true);

        [$sections, $layout] = $isDefinition
            ? [$this->parseDefinitionSheet($header, $rows, $mapping), 'xlsx-definition']
            : [$this->parseHeaderRowSheet($header, $rows), 'xlsx-header-row'];

        if ($sections === []) {
            throw new ImportException('No usable columns or rows were found in that sheet.');
        }

        $title = $fallbackTitle ?: ($sheet->getTitle() ?: 'Imported form');

        $schema = $this->normaliser->normalise([
            'title' => $title,
            'sections' => $sections,
        ], $title);

        return new ParseResult(
            schema: $schema,
            detections: $this->remapDetections($sections, $schema),
            warnings: $this->warnings,
            layout: $layout,
        );
    }

    // ── Reading ──────────────────────────────────────────────────────────

    /**
     * @return array<int, array<int, string>>
     */
    private function readRows(Worksheet $sheet): array
    {
        $rows = [];
        $truncatedCols = false;

        foreach ($sheet->getRowIterator() as $i => $row) {
            if (count($rows) >= self::MAX_ROWS) {
                $this->warn('too_many_rows', 'Only the first '.self::MAX_ROWS.' rows were read.');
                break;
            }

            $cells = [];
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);

            foreach ($iterator as $cell) {
                if (count($cells) >= self::MAX_COLUMNS) {
                    $truncatedCols = true;
                    break;
                }

                $value = $cell->getValue();
                $cells[] = is_scalar($value) ? trim((string) $value) : '';
            }

            // Trailing empty cells are noise from the sheet's used range.
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($truncatedCols) {
            $this->warn('too_many_columns', 'Only the first '.self::MAX_COLUMNS.' columns were read.');
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $header
     * @return array<int, string>  column index => canonical name
     */
    private function mapHeader(array $header): array
    {
        $mapping = [];

        foreach ($header as $i => $name) {
            $key = mb_strtolower(trim($name));
            $key = preg_replace('/[^a-z ]/', '', $key);
            $key = trim(preg_replace('/\s+/', ' ', (string) $key));

            if (isset(self::DEFINITION_HEADERS[$key])) {
                $mapping[$i] = self::DEFINITION_HEADERS[$key];
            }
        }

        return $mapping;
    }

    // ── Layout A ─────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, string>  $mapping
     * @return array<int, array<string, mixed>>
     */
    private function parseDefinitionSheet(array $header, array $rows, array $mapping): array
    {
        $bySection = [];

        foreach ($rows as $n => $row) {
            $get = function (string $name) use ($row, $mapping): ?string {
                foreach ($mapping as $i => $canonical) {
                    if ($canonical === $name && isset($row[$i]) && $row[$i] !== '') {
                        return $row[$i];
                    }
                }

                return null;
            };

            $label = $get('label');

            if ($label === null) {
                // A blank row between groups is normal; a row with data but no
                // label is not, and is worth reporting.
                if (array_filter($row) !== []) {
                    $this->warn('row_without_label', 'Row '.($n + 2).' has data but no label, so it was skipped.',
                        implode(' | ', array_slice($row, 0, 5)));
                }

                continue;
            }

            $options = $this->splitOptions($get('options'));
            $declared = $get('type');

            [$type, $confidence, $reason] = $this->resolveDeclaredType($declared, $label, $options, $n + 2);

            $id = FieldFactory::fieldId();

            $this->detections[$id] = [
                'confidence' => $confidence,
                'reason' => $reason,
                'source' => 'parser',
            ];

            $field = [
                'id' => $id,
                'type' => $type->value,
                'label' => $label,
                'required' => $this->truthy($get('required')),
                'help' => $get('help'),
                'placeholder' => $get('placeholder'),
            ];

            if ($type->hasOptions()) {
                $field['options'] = $options;
            }

            $section = $get('section') ?: 'Details';
            $bySection[$section][] = $field;
        }

        $sections = [];

        foreach ($bySection as $title => $fields) {
            $sections[] = ['title' => $title, 'fields' => $fields];
        }

        return $sections;
    }

    /**
     * A declared type is authoritative when we recognise it.
     *
     * @param  array<int, string>  $options
     * @return array{0: FieldType, 1: string, 2: string}
     */
    private function resolveDeclaredType(?string $declared, string $label, array $options, int $rowNumber): array
    {
        if ($declared !== null && $declared !== '') {
            // Reuse the normaliser's alias table so "select" works in a
            // spreadsheet exactly as it does in an AI response.
            $probe = $this->normaliser->normalise([
                'title' => 'probe',
                'fields' => [['type' => $declared, 'label' => $label]],
            ]);

            $resolved = FieldType::tryFrom($probe['sections'][0]['fields'][0]['type'] ?? '');

            if ($resolved !== null) {
                return [$resolved, ParseResult::HIGH, 'the sheet declared type "'.$declared.'"'];
            }

            $this->warn(
                'unknown_type',
                'Row '.$rowNumber.' declared an unrecognised type, so it was guessed from the label instead.',
                $declared,
            );
        }

        $guess = $this->guesser->guess($label, $options ?: null);

        return [$guess['type'], $guess['confidence'], $guess['reason']];
    }

    // ── Layout B ─────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function parseHeaderRowSheet(array $header, array $rows): array
    {
        $sample = $rows[0] ?? [];
        $fields = [];

        foreach ($header as $i => $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $guess = $this->guesser->guessFromSample($name, $sample[$i] ?? null);

            $id = FieldFactory::fieldId();

            $this->detections[$id] = [
                'confidence' => $guess['confidence'],
                'reason' => $guess['reason'],
                'source' => 'parser',
            ];

            $field = [
                'id' => $id,
                'type' => $guess['type']->value,
                'label' => $name,
                'required' => false,
            ];

            // A column with few distinct values across the sheet is a choice
            // list in disguise, and reading it as one is far more useful than
            // a free-text box.
            if ($choices = $this->inferChoices($rows, $i)) {
                $field['type'] = FieldType::Dropdown->value;
                $field['options'] = $choices;

                $this->detections[$id] = [
                    'confidence' => ParseResult::HIGH,
                    'reason' => 'only '.count($choices).' distinct values appear in this column',
                    'source' => 'parser',
                ];
            }

            $fields[] = $field;
        }

        return $fields === [] ? [] : [['title' => 'Details', 'fields' => $fields]];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, string>|null
     */
    private function inferChoices(array $rows, int $column): ?array
    {
        $values = [];
        $nonEmpty = 0;

        foreach ($rows as $row) {
            $value = trim($row[$column] ?? '');

            if ($value === '') {
                continue;
            }

            $nonEmpty++;

            if (! in_array($value, $values, true)) {
                $values[] = $value;
            }

            if (count($values) > 8) {
                return null; // too varied to be a choice list
            }
        }

        // A choice list is proved by REPETITION, not by a small count.
        //
        // The first version of this checked only "between 2 and 8 distinct
        // values", and on a five-row sheet that made every column a dropdown —
        // including full names and email addresses, where all five values were
        // unique. Distinct-equals-total is the signature of free text.
        //
        // So: enough populated rows to be evidence, and distinct values no more
        // than 60% of them, meaning values genuinely recur.
        if ($nonEmpty < 4 || count($values) < 2) {
            return null;
        }

        return count($values) <= (int) floor($nonEmpty * 0.6) ? $values : null;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @return array<int, string>
     */
    private function splitOptions(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        // Pipe first, since commas appear inside option labels far more often
        // than pipes do.
        $parts = str_contains($raw, '|')
            ? explode('|', $raw)
            : preg_split('/[,;\n]+/', $raw);

        $out = [];

        foreach ($parts ?: [] as $part) {
            $part = trim($part);

            if ($part !== '' && ! in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    private function truthy(?string $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['yes', 'y', 'true', '1', 'required', 'x'], true);
    }

    private function warn(string $type, string $message, ?string $excerpt = null): void
    {
        if (count($this->warnings) >= 50) {
            return;
        }

        $this->warnings[] = [
            'type' => $type,
            'message' => $message,
            'excerpt' => $excerpt === null ? null : mb_substr($excerpt, 0, 200),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, mixed>  $schema
     * @return array<string, array{confidence: string, reason: string, source: string}>
     */
    private function remapDetections(array $sections, array $schema): array
    {
        $before = [];

        foreach ($sections as $section) {
            foreach ($section['fields'] as $field) {
                $before[] = $field['id'];
            }
        }

        $remapped = [];
        $i = 0;

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $old = $before[$i] ?? null;

                if ($old !== null && isset($this->detections[$old])) {
                    $remapped[$field['id']] = $this->detections[$old];
                }

                $i++;
            }
        }

        return $remapped;
    }
}
