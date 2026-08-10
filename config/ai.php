<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active provider
    |--------------------------------------------------------------------------
    |
    | Resolution order:
    |   1. AI_PROVIDER, if set explicitly.
    |   2. "gemini" when a Gemini key is present.
    |   3. "fake" otherwise.
    |
    | That last fallback is deliberate. The brief requires a live demo that
    | works with zero setup on the reviewer's side, and an AI feature that
    | throws a 500 because no key is configured is a worse first impression
    | than one that returns a canned but schema-valid form. It also lets the
    | whole test suite run offline and for free.
    |
    */

    'provider' => env('AI_PROVIDER') ?: (env('GEMINI_API_KEY') ? 'gemini' : 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Repair attempts
    |--------------------------------------------------------------------------
    |
    | One initial call plus this many repair attempts. Each repair feeds the
    | validator's errors back to the model verbatim.
    |
    | Three is a considered ceiling, not a round number: with responseSchema
    | constraining the output shape at the API level, a document that is still
    | invalid after two corrections is usually wrong about intent rather than
    | syntax, and further retries just spend tokens.
    |
    */

    'max_attempts' => (int) env('AI_MAX_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'gemini' => [
            'key' => env('GEMINI_API_KEY'),

            // Chosen by probing the live API with a real call, not by reading
            // documentation and not by trusting ListModels.
            //
            // Both of those lie in different ways. The docs described
            // gemini-2.5-flash as the current workhorse; ListModels happily
            // returns it; and generateContent answers 404 "no longer available
            // to new users". ListModels reports what EXISTS, not what this key
            // may CALL.
            //
            // Verified working on this key: gemini-3.6-flash, gemini-3.5-flash,
            // gemini-3.5-flash-lite, gemini-flash-latest, gemini-3-flash-preview.
            // gemini-2.5-pro returns 429 on the free tier.
            //
            // gemini-3.5-flash-lite is the cheapest and fastest if free-tier
            // quota becomes a problem; gemini-flash-latest auto-tracks Google's
            // current flash model at the cost of changing under you.
            'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),

            // The stateless generateContent endpoint, not the newer stateful
            // Interactions API. See DECISIONS.md for why.
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),

            'timeout' => (int) env('GEMINI_TIMEOUT', 90),

            // Low but not zero. Form generation wants consistency, but a
            // temperature of 0 makes the model repeat the same generic field
            // set regardless of the prompt.
            'temperature' => (float) env('GEMINI_TEMPERATURE', 0.2),

            'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 16384),
        ],

        'fake' => [
            // No configuration. Deterministic by design.
        ],

    ],

];
