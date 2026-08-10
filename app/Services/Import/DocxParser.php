<?php

namespace App\Services\Import;

use App\Forms\FieldFactory;
use App\Forms\SchemaNormaliser;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Throwable;

/**
 * Turns a .docx into a form schema, deterministically.
 *
 * The brief's mapping, implemented literally:
 *
 *   headings                  -> sections
 *   questions                 -> fields
 *   checkbox or choice lists  -> options
 *
 * No LLM is involved here at all. Everything this class produces is
 * reproducible from the document alone, which is what makes the hybrid
 * defensible: the AI is only asked about what this could not settle.
 *
 * DEFENSIVE BY CONSTRUCTION. The brief says reviewers will test with their own
 * documents, so every element is parsed inside a try/catch and anything
 * unreadable becomes a warning rather than an exception. A document that
 * yields two good fields and one warning is a far better outcome than a 500.
 */
class DocxParser
{
    /** Bullet and checkbox glyphs Word writes into paragraph text. */
    private const CHECKBOX_GLYPHS = '☐☑☒□■▢✓✔●○◯[ ]';

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
            $document = IOFactory::load($path, 'Word2007');
        } catch (Throwable $e) {
            throw new ImportException(
                'That file could not be opened as a Word document. '
                .'It may be a .doc rather than a .docx, or corrupted.',
            );
        }

        // Flatten the document to a list of {kind, text, options} so the
        // grouping logic below does not also have to know PhpWord's tree.
        $blocks = $this->flatten($document);

        if ($blocks === []) {
            throw new ImportException('No readable text was found in that document.');
        }

        [$sections, $title] = $this->group($blocks, $fallbackTitle);

        if ($sections === []) {
            throw new ImportException(
                'No questions were recognised. Questions are detected from lines ending in "?", '
                .'numbered lines, and lines followed by a checkbox or bullet list.'
            );
        }

        $schema = $this->normaliser->normalise([
            'title' => $title,
            'sections' => $sections,
        ], $fallbackTitle ?? 'Imported form');

        // The normaliser regenerates ids, so detections keyed by the temporary
        // id are remapped onto the final ones by position.
        return new ParseResult(
            schema: $schema,
            detections: $this->remapDetections($sections, $schema),
            warnings: $this->warnings,
            layout: 'docx',
        );
    }

    // ── Stage 1: flatten ─────────────────────────────────────────────────

    /**
     * @return array<int, array{kind: string, text: string, level: int}>
     */
    private function flatten($document): array
    {
        $blocks = [];

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                try {
                    $blocks = array_merge($blocks, $this->readElement($element));
                } catch (Throwable $e) {
                    // One bad element must not lose the rest of the document.
                    $this->warn('unreadable_element', 'A block could not be read and was skipped: '.$e->getMessage());
                }
            }
        }

        return $blocks;
    }

    /**
     * @return array<int, array{kind: string, text: string, level: int}>
     */
    private function readElement($element): array
    {
        // A real Word heading: the strongest possible section signal.
        if ($element instanceof Title) {
            $text = $this->textOf($element->getText());

            return $text === '' ? [] : [['kind' => 'heading', 'text' => $text, 'level' => (int) $element->getDepth()]];
        }

        // Two classes, and both must be handled BEFORE the TextRun branch
        // below, because ListItemRun extends TextRun.
        //
        // Which one you get depends on provenance: PhpWord creates ListItem
        // when you build a document in memory, but its Word2007 reader
        // reconstructs bullets as ListItemRun. A parser that only knew about
        // ListItem silently treated every option as a separate question — the
        // choice list under "Highest qualification" became four text fields.
        if ($element instanceof ListItemRun) {
            $text = $this->textOf($element);

            return $text === '' ? [] : [['kind' => 'list', 'text' => $text, 'level' => 0]];
        }

        if ($element instanceof ListItem) {
            $text = $this->textOf($element->getTextObject()?->getText());

            return $text === '' ? [] : [['kind' => 'list', 'text' => $text, 'level' => 0]];
        }

        if ($element instanceof Table) {
            return $this->readTable($element);
        }

        if ($element instanceof TextBreak) {
            return [];
        }

        if ($element instanceof Text || $element instanceof TextRun) {
            $text = $this->textOf($element);

            if ($text === '') {
                return [];
            }

            // Documents written without Word's heading styles still mark
            // sections, usually in bold or ALL CAPS with no question mark.
            $style = $element->getParagraphStyle();
            $styleName = is_string($style) ? $style : ($style?->getStyleName() ?? '');

            if (preg_match('/^Heading\s*(\d)/i', (string) $styleName, $m)) {
                return [['kind' => 'heading', 'text' => $text, 'level' => (int) $m[1]]];
            }

            // A short ALL-CAPS line with no question mark is a heading in a
            // document that never used Word's heading styles. Very common in
            // forms typed rather than authored, and without this the title of
            // the document becomes its first question.
            if ($this->looksLikeShoutedHeading($text)) {
                return [['kind' => 'heading', 'text' => $this->titleCase($text), 'level' => 1]];
            }

            return [['kind' => 'text', 'text' => $text, 'level' => 0]];
        }

        return [];
    }

    /**
     * Tables in forms are usually a two-column "Label | blank" grid.
     *
     * @return array<int, array{kind: string, text: string, level: int}>
     */
    private function readTable(Table $table): array
    {
        $blocks = [];

        foreach ($table->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $text = '';

                foreach ($cell->getElements() as $element) {
                    $text .= ' '.$this->textOf($element);
                }

                $cells[] = trim($text);
            }

            $cells = array_values(array_filter($cells, fn ($c) => $c !== ''));

            if ($cells === []) {
                continue;
            }

            // First cell is the question; the rest is either an answer space
            // (ignored) or a set of choices.
            $blocks[] = ['kind' => 'text', 'text' => $cells[0], 'level' => 0];

            foreach (array_slice($cells, 1) as $choice) {
                if ($this->looksLikeChoice($choice)) {
                    $blocks[] = ['kind' => 'list', 'text' => $choice, 'level' => 0];
                }
            }
        }

        return $blocks;
    }

    // ── Stage 2: group into sections and fields ──────────────────────────

    /**
     * @param  array<int, array{kind: string, text: string, level: int}>  $blocks
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    private function group(array $blocks, ?string $fallbackTitle): array
    {
        $sections = [];
        $current = null;
        $pendingField = null;
        $pendingOptions = [];
        $title = null;

        $flushField = function () use (&$current, &$pendingField, &$pendingOptions) {
            if ($pendingField === null) {
                return;
            }

            $current ??= ['title' => null, 'fields' => []];

            $current['fields'][] = $this->buildField($pendingField, $pendingOptions);

            $pendingField = null;
            $pendingOptions = [];
        };

        foreach ($blocks as $block) {
            $text = $block['text'];

            if ($block['kind'] === 'heading') {
                $flushField();

                // The very first heading, before any question, is the document
                // title rather than a section.
                if ($title === null && $current === null) {
                    $title = $text;

                    continue;
                }

                if ($current !== null) {
                    $sections[] = $current;
                }

                $current = ['title' => $this->stripNumbering($text), 'fields' => []];

                continue;
            }

            if ($block['kind'] === 'list' || $this->looksLikeChoice($text)) {
                if ($pendingField === null) {
                    // A list with no question above it is not something we can
                    // interpret, and silently dropping it would hide content.
                    $this->warn('orphan_list', 'A list appears with no question above it and was ignored.', $text);

                    continue;
                }

                $pendingOptions[] = $this->stripChoiceGlyphs($text);

                continue;
            }

            // Plain text. Is it a question?
            if ($this->looksLikeQuestion($text)) {
                $flushField();
                $pendingField = $text;

                continue;
            }

            // Not a heading, not a list, not a question. Long prose is
            // instructions; a short line after a question is likely its help
            // text; anything else gets reported.
            if ($pendingField !== null && mb_strlen($text) < 120) {
                $pendingField .= "\n".$text;

                continue;
            }

            if (mb_strlen($text) > 200) {
                continue; // introductory prose, safely ignored
            }

            $this->warn('unrecognised_line', 'This line was not recognised as a heading, question or option.', $text);
        }

        $flushField();

        if ($current !== null) {
            $sections[] = $current;
        }

        // Drop sections that ended up with no fields at all.
        $sections = array_values(array_filter($sections, fn ($s) => $s['fields'] !== []));

        return [$sections, $title ?? $fallbackTitle ?? 'Imported form'];
    }

    /**
     * @param  array<int, string>  $options
     * @return array<string, mixed>
     */
    private function buildField(string $raw, array $options): array
    {
        // A question may have accumulated a help line.
        $parts = explode("\n", $raw, 2);
        $question = $this->stripNumbering(trim($parts[0]));
        $help = isset($parts[1]) ? trim($parts[1]) : null;

        $required = (bool) preg_match('/\*|\(required\)|\brequired\b/iu', $question);
        $question = trim(preg_replace('/\s*\*\s*$|\(required\)/iu', '', $question));

        $guess = $this->guesser->guess($question, $options ?: null);

        $id = FieldFactory::fieldId();

        $this->detections[$id] = [
            'confidence' => $guess['confidence'],
            'reason' => $guess['reason'],
            'source' => 'parser',
        ];

        $field = [
            'id' => $id,
            'type' => $guess['type']->value,
            'label' => rtrim($question, ':'),
            'required' => $required,
            'help' => $help,
        ];

        if ($guess['type']->hasOptions()) {
            $field['options'] = $options;
        }

        return $field;
    }

    // ── Recognisers ──────────────────────────────────────────────────────

    private function looksLikeQuestion(string $text): bool
    {
        if (mb_strlen($text) > 300) {
            return false;
        }

        return (bool) preg_match('/\?\s*$/u', $text)          // ends with ?
            || (bool) preg_match('/^\s*\d+[.)]\s+/u', $text)  // 1. or 1)
            || (bool) preg_match('/:\s*$|_{3,}/u', $text)     // "Name:" or a fill-in rule
            || (bool) preg_match('/^[A-Z][^.!?]{2,80}$/u', $text); // a short capitalised line
    }

    /**
     * "CUSTOMER FEEDBACK" is a heading. "PHP" is an option. The difference is
     * length and the absence of a question mark, so both are required.
     */
    private function looksLikeShoutedHeading(string $text): bool
    {
        if (mb_strlen($text) < 6 || mb_strlen($text) > 60 || str_contains($text, '?')) {
            return false;
        }

        // Must contain letters, and none of them lowercase.
        return (bool) preg_match('/\p{Lu}/u', $text)
            && ! preg_match('/\p{Ll}/u', $text);
    }

    private function titleCase(string $text): string
    {
        return mb_convert_case(mb_strtolower($text), MB_CASE_TITLE, 'UTF-8');
    }

    private function looksLikeChoice(string $text): bool
    {
        $glyphs = preg_quote(self::CHECKBOX_GLYPHS, '/');

        return (bool) preg_match('/^\s*[' .$glyphs.']/u', $text)
            || (bool) preg_match('/^\s*\[\s*\]/u', $text)
            || (bool) preg_match('/^\s*[-•*o]\s+\S/u', $text);
    }

    private function stripChoiceGlyphs(string $text): string
    {
        $glyphs = preg_quote(self::CHECKBOX_GLYPHS, '/');

        return trim(preg_replace('/^\s*(\[\s*\]|['.$glyphs.']|[-•*o])\s*/u', '', $text));
    }

    private function stripNumbering(string $text): string
    {
        return trim(preg_replace('/^\s*\d+[.)]\s*/u', '', $text));
    }

    private function textOf($element): string
    {
        if ($element === null) {
            return '';
        }

        if (is_string($element)) {
            return trim(preg_replace('/\s+/u', ' ', $element));
        }

        if ($element instanceof Text) {
            return $this->textOf($element->getText());
        }

        if ($element instanceof TextRun) {
            $text = '';

            foreach ($element->getElements() as $child) {
                $text .= $this->textOf($child);
            }

            return trim(preg_replace('/\s+/u', ' ', $text));
        }

        if (method_exists($element, 'getText')) {
            return $this->textOf($element->getText());
        }

        return '';
    }

    // ── Bookkeeping ──────────────────────────────────────────────────────

    private function warn(string $type, string $message, ?string $excerpt = null): void
    {
        // Cap the list: a pathological document could otherwise produce
        // thousands of warnings and make the review screen unusable.
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
     * The normaliser mints its own ids, so detections are re-keyed by position.
     *
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

        $after = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $after[] = $field['id'];
            }
        }

        $remapped = [];

        foreach ($after as $i => $id) {
            $old = $before[$i] ?? null;

            if ($old !== null && isset($this->detections[$old])) {
                $remapped[$id] = $this->detections[$old];
            }
        }

        return $remapped;
    }
}
