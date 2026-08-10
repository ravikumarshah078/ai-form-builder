<?php

namespace App\Services\Import;

use App\Enums\FieldType;

/**
 * Guesses a field type from a question's wording, and says how sure it is.
 *
 * THIS IS THE DETERMINISTIC HALF of the hybrid the brief asks us to explain.
 * It runs first, on every field, with no LLM involved. It is fully
 * reproducible: the same document always yields the same result.
 *
 * The confidence value is the whole point. A question containing "email
 * address" is an email field and no model is needed to say so. A question
 * reading "Details" could be anything, and that is where an LLM earns its
 * latency. Only LOW confidence fields are sent to SchemaRefiner.
 *
 * Ordering matters: the most specific patterns are tested first, because
 * "What is your email address?" also contains "address".
 */
class FieldTypeGuesser
{
    /**
     * Patterns tried in order. First match wins.
     *
     * Each entry: [regex, type, confidence, reason shown on the review screen]
     *
     * @var array<int, array{0: string, 1: FieldType, 2: string, 3: string}>
     */
    private const PATTERNS = [
        // ── Unambiguous: a single well-known noun ────────────────────────
        ['/\b(e-?mail|email address)\b/iu', FieldType::Email, ParseResult::HIGH, 'mentions email'],
        ['/\b(phone|mobile|telephone|contact number|whatsapp)\b/iu', FieldType::Phone, ParseResult::HIGH, 'mentions a phone number'],
        ['/\b(website|url|link|linkedin|github|portfolio site)\b/iu', FieldType::Url, ParseResult::HIGH, 'mentions a web address'],

        // ── Upload ──────────────────────────────────────────────────────
        ['/\b(upload|attach|attachment|resume|cv|curriculum vitae|photograph|scan(ned)? copy|document)\b/iu', FieldType::File, ParseResult::HIGH, 'asks for a file'],

        // ── Date and time ───────────────────────────────────────────────
        ['/\b(date of birth|dob|birth ?date)\b/iu', FieldType::Date, ParseResult::HIGH, 'asks for a date of birth'],
        ['/\b(date|deadline|when did|when will|from|until|expiry|joining|graduation)\b.*\b(date)?\b/iu', FieldType::Date, ParseResult::HIGH, 'mentions a date'],
        ['/\b(time|hour|slot)\b/iu', FieldType::Time, ParseResult::LOW, 'mentions time, but may be a duration'],

        // ── Numbers ─────────────────────────────────────────────────────
        ['/\b(how many|number of|quantity|count|age|years of experience|salary|amount|price|cost|budget|pin ?code|zip)\b/iu', FieldType::Number, ParseResult::HIGH, 'asks for a quantity'],

        // ── Rating ──────────────────────────────────────────────────────
        ['/\b(rate|rating|score|out of (5|10)|scale of|how satisfied|how likely)\b/iu', FieldType::Rating, ParseResult::HIGH, 'asks for a rating'],

        // ── Long text ───────────────────────────────────────────────────
        ['/\b(describe|explain|comments?|feedback|remarks|why |tell us|elaborate|summary|additional info|anything else|cover letter)\b/iu', FieldType::Textarea, ParseResult::HIGH, 'asks for a longer answer'],

        // ── Short text, named ───────────────────────────────────────────
        ['/\b(full name|first name|last name|surname|your name|applicant name|father|mother|guardian)\b/iu', FieldType::Text, ParseResult::HIGH, 'asks for a name'],
        ['/\b(address|street|city|state|country|locality)\b/iu', FieldType::Textarea, ParseResult::LOW, 'mentions an address; may be one line or several'],
    ];

    /**
     * @return array{type: FieldType, confidence: string, reason: string}
     */
    public function guess(string $question, ?array $options = null): array
    {
        $q = trim($question);

        // A question that came with a choice list IS a choice field. That is
        // structural evidence from the document, which beats any guess made
        // from wording, so it is checked before the patterns.
        if ($options !== null && $options !== []) {
            $multi = (bool) preg_match(
                '/\b(select all|choose all|tick all|check all|all that apply|multiple)\b/iu',
                $q
            );

            return [
                'type' => $multi ? FieldType::Checkbox : ($this->manyOptions($options) ? FieldType::Dropdown : FieldType::Radio),
                'confidence' => ParseResult::HIGH,
                'reason' => $multi
                    ? 'a choice list, and the wording allows several answers'
                    : 'a choice list of '.count($options).' options',
            ];
        }

        foreach (self::PATTERNS as [$pattern, $type, $confidence, $reason]) {
            if (preg_match($pattern, $q)) {
                return ['type' => $type, 'confidence' => $confidence, 'reason' => $reason];
            }
        }

        // Nothing matched. Default to a single-line text box, but mark it LOW
        // so the AI step gets a chance to do better.
        return [
            'type' => FieldType::Text,
            'confidence' => ParseResult::LOW,
            'reason' => 'no recognisable pattern; defaulted to a text box',
        ];
    }

    /**
     * A long list is unwieldy as radio buttons.
     *
     * @param  array<int, mixed>  $options
     */
    private function manyOptions(array $options): bool
    {
        return count($options) > 5;
    }

    /**
     * Refine a guess using a sample answer, for spreadsheets where row 2 holds
     * example data. Evidence from real values beats wording.
     */
    public function guessFromSample(string $question, ?string $sample): array
    {
        $guess = $this->guess($question);

        if ($sample === null || trim($sample) === '' || $guess['confidence'] === ParseResult::HIGH) {
            return $guess;
        }

        $sample = trim($sample);

        // ORDER MATTERS, and getting it wrong is subtle. "2024-06-01" satisfies
        // a naive phone pattern — it starts with a digit and contains only
        // digits and separators — so a date column was being read as a phone
        // number. Dates and datetimes are therefore tested first, and the phone
        // pattern below additionally refuses anything shaped like a date.
        $refined = match (true) {
            (bool) filter_var($sample, FILTER_VALIDATE_EMAIL) => [FieldType::Email, 'the sample value is an email address'],
            (bool) filter_var($sample, FILTER_VALIDATE_URL) => [FieldType::Url, 'the sample value is a URL'],

            (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $sample) => [FieldType::DateTime, 'the sample value is a date and time'],
            (bool) preg_match('#^\d{4}-\d{2}-\d{2}$|^\d{1,2}[/-]\d{1,2}[/-]\d{2,4}$#', $sample) => [FieldType::Date, 'the sample value is a date'],
            (bool) preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $sample) => [FieldType::Time, 'the sample value is a time'],

            // At least seven digits, and not a date.
            (bool) preg_match('/^\+?[\d][\d\s().-]{6,}$/', $sample)
                && preg_match_all('/\d/', $sample) >= 7 => [FieldType::Phone, 'the sample value looks like a phone number'],

            is_numeric($sample) => [FieldType::Number, 'the sample value is numeric'],
            mb_strlen($sample) > 80 => [FieldType::Textarea, 'the sample value is long'],
            default => null,
        };

        if ($refined === null) {
            return $guess;
        }

        return ['type' => $refined[0], 'confidence' => ParseResult::HIGH, 'reason' => $refined[1]];
    }
}
