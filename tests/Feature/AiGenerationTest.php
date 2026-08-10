<?php

use App\Forms\SchemaValidator;
use App\Jobs\RunAiGeneration;
use App\Models\AiGeneration;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\FakeProvider;
use App\Services\Ai\FormGenerator;
use App\Services\Ai\FormPrompt;
use App\Services\Ai\LlmException;

/**
 * Part B: AI generation and AI editing.
 *
 * Everything here runs against FakeProvider, which is deterministic and
 * offline. The point is not to test Google's model — it is to test OUR
 * handling of what a model returns, especially when that is wrong.
 */

beforeEach(function () {
    FakeProvider::reset();

    $this->provider = new FakeProvider;
    app()->instance(LlmProvider::class, $this->provider);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function generation(array $attributes = []): AiGeneration
{
    return AiGeneration::create(array_merge([
        'user_id' => test()->user->id,
        'mode' => 'create',
        'prompt' => 'An internship application with education history, skills and resume upload',
        'provider' => 'fake',
        'model' => 'fake-deterministic',
    ], $attributes));
}

function runGeneration(AiGeneration $g): AiGeneration
{
    return (new FormGenerator(test()->provider))->run($g);
}

// ── Happy path ───────────────────────────────────────────────────────────

it('produces a valid schema from a prompt', function () {
    $g = runGeneration(generation());

    expect($g->status)->toBe('succeeded')
        ->and($g->result_schema)->toBeValidSchema();
});

it('logs model, tokens, latency and attempts', function () {
    $g = runGeneration(generation());

    // The brief asks for exactly these.
    expect($g->model)->toBe('fake-deterministic')
        ->and($g->input_tokens)->toBeGreaterThan(0)
        ->and($g->output_tokens)->toBeGreaterThan(0)
        ->and($g->latency_ms)->toBeGreaterThan(0)
        ->and($g->attempts)->toBe(1);
});

it('keeps the raw response for debugging', function () {
    $g = runGeneration(generation());

    expect($g->raw_response)->not->toBeEmpty();
});

it('honours the prompt when choosing sections', function () {
    $g = runGeneration(generation(['prompt' => 'A feedback survey with a rating and comments']));

    $titles = array_column($g->result_schema['sections'], 'title');

    expect($titles)->toContain('Feedback');
});

// ── Repair loop ──────────────────────────────────────────────────────────

it('repairs a response that is not JSON at all', function () {
    FakeProvider::queue(
        'Sure! Here is your form:',                       // attempt 1: prose
        json_encode(['title' => 'Recovered', 'sections' => [[
            'title' => 'S', 'fields' => [['type' => 'text', 'label' => 'Name']],
        ]]])                                              // attempt 2: valid
    );

    $g = runGeneration(generation());

    expect($g->status)->toBe('succeeded')
        ->and($g->attempts)->toBe(2)
        ->and($g->result_schema['title'])->toBe('Recovered');
});


it('feeds the validation errors back to the model', function () {
    FakeProvider::queue(
        json_encode(['title' => 'Bad', 'sections' => [[
            'title' => 'S',
            'fields' => [['type' => 'quantum_widget', 'label' => 'Q']],
        ]]]),
        json_encode(['title' => 'Good', 'sections' => [[
            'title' => 'S', 'fields' => [['type' => 'text', 'label' => 'Q']],
        ]]])
    );

    $g = runGeneration(generation());

    expect($g->status)->toBe('succeeded')->and($g->attempts)->toBe(2);

    // The second prompt must name the offending value and the legal set,
    // otherwise the model is being asked to fix something it cannot see.
    $second = FakeProvider::calls()[1]['user'];

    expect($second)->toContain('quantum_widget')
        ->and($second)->toContain('dropdown')
        ->and($second)->toContain('previous response');
});

it('gives up after the configured number of attempts', function () {
    config(['ai.max_attempts' => 3]);

    // Always invalid.
    $bad = json_encode(['title' => 'Bad', 'sections' => [[
        'title' => 'S', 'fields' => [['type' => 'not_a_type', 'label' => 'X']],
    ]]]);

    FakeProvider::queue($bad, $bad, $bad);

    $g = runGeneration(generation());

    expect($g->status)->toBe('failed')
        ->and($g->attempts)->toBe(3)
        ->and(FakeProvider::callCount())->toBe(3);
});

it('never persists a schema that failed validation', function () {
    $bad = json_encode(['title' => 'Bad', 'sections' => [[
        'title' => 'S', 'fields' => [['type' => 'not_a_type', 'label' => 'X']],
    ]]]);

    FakeProvider::queue($bad, $bad, $bad);

    $g = runGeneration(generation());

    // The brief: "never persist a broken schema".
    expect($g->result_schema)->toBeNull()
        ->and($g->error)->not->toBeEmpty()
        // The raw response is kept precisely so the failure can be diagnosed.
        ->and($g->raw_response)->not->toBeEmpty();
});

it('repairs mechanically without spending a retry', function () {
    // "select", bare-string options and a missing key are all unambiguous, so
    // SchemaNormaliser fixes them and no second call is made.
    FakeProvider::queue(json_encode(['title' => 'Mech', 'sections' => [[
        'title' => 'S',
        'fields' => [
            ['type' => 'select', 'label' => 'Country', 'options' => ['India', 'UK']],
            ['type' => 'e-mail', 'label' => 'Email'],
        ],
    ]]]));

    $g = runGeneration(generation());

    expect($g->status)->toBe('succeeded')
        ->and($g->attempts)->toBe(1)
        ->and(FakeProvider::callCount())->toBe(1)
        ->and($g->result_schema['sections'][0]['fields'][0]['type'])->toBe('dropdown')
        ->and($g->result_schema['sections'][0]['fields'][1]['type'])->toBe('email');
});

it('maps media types to file extensions without a retry', function () {
    // A real Gemini response failed on exactly this and self-corrected, which
    // cost a round trip. It is now handled deterministically.
    FakeProvider::queue(json_encode(['title' => 'Docs', 'sections' => [[
        'title' => 'S',
        'fields' => [[
            'type' => 'file', 'label' => 'Resume',
            'validation' => ['mimes' => ['application/pdf', 'application/msword']],
        ]],
    ]]]));

    $g = runGeneration(generation());

    expect($g->attempts)->toBe(1)
        ->and($g->result_schema['sections'][0]['fields'][0]['validation']['mimes'])->toBe(['pdf', 'doc']);
});

// ── Transport failures ───────────────────────────────────────────────────

it('retries a retryable transport error', function () {
    FakeProvider::queue(
        LlmException::api('rate limited', 429),
        json_encode(['title' => 'After retry', 'sections' => [[
            'title' => 'S', 'fields' => [['type' => 'text', 'label' => 'Name']],
        ]]])
    );

    $g = runGeneration(generation());

    expect($g->status)->toBe('succeeded');
});

it('does not retry a non-retryable API error', function () {
    FakeProvider::queue(LlmException::api('bad request', 400));

    $g = runGeneration(generation());

    expect($g->status)->toBe('failed')
        ->and(FakeProvider::callCount())->toBe(1);
});

it('reports a truncated response as truncation rather than bad JSON', function () {
    config(['ai.max_attempts' => 1]);

    app()->instance(LlmProvider::class, new class extends FakeProvider
    {
        public function generateJson(string $s, string $u, array $r, array $o = []): App\Services\Ai\LlmResponse
        {
            return new App\Services\Ai\LlmResponse(
                text: '{"title":"Cut off","sections":[{"title":"S","fi',
                model: 'fake', latencyMs: 1, finishReason: 'MAX_TOKENS',
            );
        }
    });

    $g = (new FormGenerator(app(LlmProvider::class)))->run(generation());

    expect($g->status)->toBe('failed')
        ->and($g->error)->toContain('cut off');
});

// ── JSON extraction ──────────────────────────────────────────────────────

it('extracts JSON from a markdown fence', function () {
    $g = new FormGenerator($this->provider);

    expect($g->extractJson("```json\n{\"a\":1}\n```"))->toBe(['a' => 1])
        ->and($g->extractJson("```\n{\"a\":1}\n```"))->toBe(['a' => 1])
        ->and($g->extractJson('Here you go: {"a":1}'))->toBe(['a' => 1])
        ->and($g->extractJson('{"a":1}'))->toBe(['a' => 1])
        ->and($g->extractJson('not json at all'))->toBeNull();
});

// ── The prompt contract ──────────────────────────────────────────────────

it('constrains the field type enum from the FieldType enum itself', function () {
    $schema = FormPrompt::responseSchema();
    $enum = $schema['properties']['sections']['items']['properties']['fields']['items']['properties']['type']['enum'];

    // This is the hallucination gate. If these ever diverge, the model could
    // legally return a type the application does not implement.
    expect($enum)->toBe(App\Enums\FieldType::values());
});

it('tells an editing model to preserve keys', function () {
    // Changing a key orphans answers that have already been collected.
    expect(FormPrompt::editSystem())->toContain('PRESERVE THE "key"')
        ->and(FormPrompt::editSystem())->toContain('COMPLETE form');
});

// ── The job ──────────────────────────────────────────────────────────────

it('creates a draft form from a successful generation', function () {
    $g = generation();

    (new RunAiGeneration($g->id))->handle($this->provider);

    $g->refresh();

    expect($g->form_id)->not->toBeNull();

    $form = Form::find($g->form_id);

    expect($form->status->value)->toBe('draft')          // never auto-published
        ->and($form->currentVersion->origin)->toBe('ai_create')
        ->and($form->currentVersion->version_number)->toBe(1)
        ->and($form->currentVersion->schema)->toBeValidSchema();
});

it('does not create a form when generation fails', function () {
    $bad = json_encode(['title' => 'Bad', 'sections' => [[
        'title' => 'S', 'fields' => [['type' => 'nope', 'label' => 'X']],
    ]]]);

    FakeProvider::queue($bad, $bad, $bad);

    $before = Form::count();

    (new RunAiGeneration(generation()->id))->handle($this->provider);

    expect(Form::count())->toBe($before);
});

it('applies an AI edit as the next version', function () {
    $form = Form::create([
        'user_id' => $this->user->id,
        'title' => 'Original',
        'status' => 'draft',
    ]);

    $schema = App\Forms\FieldFactory::emptySchema('Original');
    $schema['sections'][] = App\Forms\FieldFactory::makeSection([
        'fields' => [App\Forms\FieldFactory::make(App\Enums\FieldType::Text, ['key' => 'name', 'label' => 'Name'])],
    ]);

    $v1 = FormVersion::create([
        'form_id' => $form->id, 'version_number' => 1,
        'schema' => $schema, 'checksum' => FormVersion::checksumFor($schema),
        'origin' => 'manual', 'created_by' => $this->user->id,
    ]);
    $form->update(['current_version_id' => $v1->id]);

    FakeProvider::queue(json_encode([
        'title' => 'Original',
        'sections' => [[
            'title' => 'Main',
            'fields' => [
                ['type' => 'text', 'label' => 'Name', 'key' => 'name'],
                ['type' => 'phone', 'label' => 'Emergency contact', 'required' => true],
            ],
        ]],
    ]));

    $g = generation([
        'mode' => 'edit',
        'form_id' => $form->id,
        'prompt' => 'Add an emergency contact',
        'input_schema' => $schema,
    ]);

    (new RunAiGeneration($g->id))->handle($this->provider);

    $form->refresh();

    expect($form->versions()->count())->toBe(2)
        ->and($form->currentVersion->version_number)->toBe(2)
        ->and($form->currentVersion->origin)->toBe('ai_edit')
        ->and($form->currentVersion->schema['sections'][0]['fields'])->toHaveCount(2)
        // v1 is untouched, so rollback is always possible.
        ->and($v1->fresh()->schema['sections'][0]['fields'])->toHaveCount(1);
});

it('does not write a version when an edit changes nothing', function () {
    $form = Form::create(['user_id' => $this->user->id, 'title' => 'Same', 'status' => 'draft']);

    $schema = App\Forms\FieldFactory::emptySchema('Same');
    $schema['sections'][] = App\Forms\FieldFactory::makeSection([
        'fields' => [App\Forms\FieldFactory::make(App\Enums\FieldType::Text, ['key' => 'name', 'label' => 'Name'])],
    ]);

    $v1 = FormVersion::create([
        'form_id' => $form->id, 'version_number' => 1,
        'schema' => $schema, 'checksum' => FormVersion::checksumFor($schema),
        'origin' => 'manual', 'created_by' => $this->user->id,
    ]);
    $form->update(['current_version_id' => $v1->id]);

    // Hand back exactly what we sent.
    FakeProvider::queue(json_encode($schema));

    (new RunAiGeneration(generation([
        'mode' => 'edit', 'form_id' => $form->id,
        'prompt' => 'Do nothing', 'input_schema' => $schema,
    ])->id))->handle($this->provider);

    expect($form->fresh()->versions()->count())->toBe(1);
});
