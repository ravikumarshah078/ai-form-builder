<?php

namespace App\Services\Ai;

use App\Enums\FieldType;
use App\Forms\ValidationResult;

/**
 * The prompt contract: system instructions and the JSON Schema the reply must
 * conform to.
 *
 * The single most important line in this class is where the response schema's
 * `type` enum is built from FieldType::values(). That is what makes a
 * hallucinated field type structurally impossible rather than merely
 * discouraged — the model is constrained by the API, not by a sentence in the
 * prompt it may ignore.
 *
 * Defence in depth, since responseSchema guarantees SHAPE but not SENSE:
 *
 *   1. responseSchema        - the reply cannot be prose, a code fence, or an
 *                              unknown field type.
 *   2. SchemaNormaliser      - repairs the things the schema still permits:
 *                              missing keys, bare-string options, aliases.
 *   3. SchemaValidator       - rejects what remains: duplicate keys, options on
 *                              a text field, impossible ranges.
 *   4. Repair loop           - feeds those errors back and asks for a fix.
 */
class FormPrompt
{
    /**
     * System instruction for creating a form from scratch.
     */
    public static function createSystem(): string
    {
        return <<<'PROMPT'
        You design web forms. You are given a description of what a form should
        collect, and you return a single JSON document describing that form.

        Rules:

        - Return JSON only. No prose, no explanation, no markdown fences.
        - Group related questions into sections with a short, descriptive title.
        - Choose the most specific field type available. Use "email" for an email
          address rather than "text"; "phone" for a telephone number; "date" for a
          date; "file" for an upload; "dropdown" for one choice from a known list;
          "checkbox" for several choices from a list; "rating" for a 1-5 score.
        - Use "heading" and "paragraph" only for guidance text that collects no
          answer. Never mark those as required.
        - Give every question a clear label written as a question or a noun phrase,
          exactly as it should appear to the person filling in the form.
        - Set "required" true only for information the form genuinely cannot do
          without.
        - Add a "placeholder" where an example makes the expected format obvious,
          and "help" where a question needs a sentence of explanation.
        - "options" is required for dropdown, radio and checkbox, and must be
          omitted for every other type. Give each option a short machine "value"
          in lower_snake_case and a human "label".
        - Add "validation" only where a real constraint exists: max_length for free
          text, min and max for numbers and ratings, mimes and max_kb for uploads.
          Do not invent a regex unless the format is genuinely fixed, such as a
          postcode or a reference number.
        - "mimes" takes bare FILE EXTENSIONS, not media types: ["pdf", "docx"],
          never ["application/pdf", "application/msword"]. Allowed values are
          pdf, doc, docx, xls, xlsx, ppt, pptx, txt, csv, png, jpg, jpeg, gif,
          webp, svg and zip. "max_kb" is a whole number of kilobytes.
        - Write in the language the request is written in. If asked to translate,
          translate labels, placeholders, help text and option labels, but never
          the "key" or the option "value".
        PROMPT;
    }

    /**
     * System instruction for editing an existing form.
     *
     * A separate instruction rather than a flag, because the failure modes are
     * different: an editing model's temptation is to rewrite the whole form,
     * and the prompt has to push against that specifically.
     */
    public static function editSystem(): string
    {
        return static::createSystem()."\n\n".<<<'PROMPT'

        You are now EDITING an existing form. You will be given its current JSON
        and an instruction describing a change.

        Additional rules for editing:

        - Return the COMPLETE form, not a patch and not a diff.
        - Change only what the instruction asks for. Leave every other field
          exactly as it is, including its label, key, type, order and validation.
        - PRESERVE THE "key" AND "id" OF EVERY FIELD YOU KEEP. Those identify
          answers that have already been collected; changing one orphans real
          data. Only a field you are adding should be missing them.
        - If the instruction asks to remove something, remove it rather than
          hiding it.
        - If the instruction is ambiguous, choose the smallest reasonable change.
        PROMPT;
    }

    /**
     * The user turn for a fresh generation.
     */
    public static function createUser(string $description): string
    {
        return "Design a form for the following requirement:\n\n".trim($description);
    }

    /**
     * The user turn for an edit.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function editUser(array $schema, string $instruction): string
    {
        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "Here is the current form:\n\n```json\n{$json}\n```\n\n"
            ."Apply this change and return the complete updated form:\n\n".trim($instruction);
    }

    /**
     * The follow-up turn after a failed validation.
     *
     * The previous (invalid) output is included alongside the errors. Without
     * it the model is being asked to fix something it cannot see, and tends to
     * start again from the original prompt instead of correcting.
     */
    public static function repairUser(string $previousOutput, ValidationResult $result): string
    {
        return $result->toPromptFeedback()
            ."\n\nThis was your previous response:\n\n```json\n{$previousOutput}\n```\n\n"
            .'Return the complete corrected form as JSON.';
    }

    /**
     * The JSON Schema handed to the provider as `responseSchema`.
     *
     * Kept to the subset Gemini supports — object, array, string, number,
     * integer, boolean, plus enum and required. No $ref, no additionalProperties,
     * no oneOf; deeply nested or exotic schemas get rejected outright.
     *
     * Note what is deliberately ABSENT: `id`. Ids are ours to mint, and asking
     * a model to invent unique identifiers is a reliable way to get duplicates.
     * SchemaNormaliser generates them.
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'sections'],
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Short title for the form, at most 200 characters.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'One or two sentences shown above the first question.',
                ],
                'sections' => [
                    'type' => 'array',
                    'description' => 'Groups of related questions, in the order they should appear.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['title', 'fields'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'fields' => [
                                'type' => 'array',
                                'items' => static::fieldSchema(),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fieldSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['label', 'type'],
            'properties' => [
                'label' => [
                    'type' => 'string',
                    'description' => 'The question as shown to the person filling in the form.',
                ],
                'key' => [
                    'type' => 'string',
                    'description' => 'Machine name in lower_snake_case. Omit for new fields; keep unchanged when editing.',
                ],
                'type' => [
                    'type' => 'string',
                    // THE HALLUCINATION GATE. Built from the enum, so the model
                    // cannot return a type this application does not implement.
                    'enum' => FieldType::values(),
                ],
                'placeholder' => ['type' => 'string'],
                'help' => ['type' => 'string'],
                'required' => ['type' => 'boolean'],
                'options' => [
                    'type' => 'array',
                    'description' => 'Required for dropdown, radio and checkbox. Omit for every other type.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['value', 'label'],
                        'properties' => [
                            'value' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                        ],
                    ],
                ],
                'validation' => [
                    'type' => 'object',
                    'properties' => [
                        'min_length' => ['type' => 'integer'],
                        'max_length' => ['type' => 'integer'],
                        'min' => ['type' => 'number'],
                        'max' => ['type' => 'number'],
                        'regex' => ['type' => 'string'],
                        'mimes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'max_kb' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }
}
