# AI-Powered Form Builder

A Laravel + Livewire form builder with manual form creation, AI generation from a natural-language
prompt, and import from Word/Excel documents.

> **Status: Complete.**
> 256 tests, 525 assertions, all passing.

---

## Demo

| | |
|---|---|
| **Live URL** | [https://ai-form-builder-ceyx.onrender.com](https://ai-form-builder-ceyx.onrender.com) |
| **Email** | `demo@formbuilder.test` |
| **Password** | `password` |

The demo account is created by `DemoUserSeeder` and ships with one published form
(*Internship Application*, 9 fields across 3 sections) and 3 sample responses, so the app is
never seen in an empty state.

---

## Stack

| Layer | Choice | Version |
|---|---|---|
| Language | PHP | 8.3 |
| Framework | Laravel | 12.65 |
| Interactivity | Livewire | 3.8 |
| Database | MySQL | 8.0 |
| Cache + queue | Redis | 7.0 |
| Queue dashboard | Laravel Horizon | 5.48 |
| CSS | Bootstrap | 5.3 |
| Build | Vite | 7 |
| Word parsing | PhpWord | 1.4 |
| Excel parsing | PhpSpreadsheet | 5.9 |
| Drag & drop | SortableJS | 1.15 |
| LLM | Google Gemini (`gemini-3.6-flash`) | `generateContent` |
| Testing | Pest | 3 |

**On the Laravel version:** the brief asks for Laravel 10/11. Laravel 10 lost security support in
February 2025 and Laravel 11 in March 2026, so both were end-of-life before this was written.
Laravel 12 is skeleton-identical to 11, satisfies the brief's "PHP 8.2+" requirement, and is
supported until February 2027. The reasoning is expanded in [DECISIONS.md](DECISIONS.md).

---

## Setup

Requires PHP 8.3+, Composer 2, Node 20+, MySQL 8, and Redis.

```bash
git clone <repo-url> form-builder
cd form-builder

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Create the database and a user for it:

```sql
CREATE DATABASE form_builder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'form_builder'@'127.0.0.1' IDENTIFIED BY 'your-password';
GRANT ALL PRIVILEGES ON form_builder.* TO 'form_builder'@'127.0.0.1';
```

Fill in `DB_PASSWORD`, and `GEMINI_API_KEY` if you have one — without it the AI features fall
back to a deterministic offline provider rather than failing. Then:

```bash
php artisan migrate --seed
npm run build

php artisan serve          # http://127.0.0.1:8000
php artisan queue:work     # required: AI generation and document imports are queued
```

Two helper commands:

```bash
php artisan ai:models      # list the models your API key can actually reach
php artisan import:samples # regenerate the sample .docx / .xlsx files
```

### Environment variables

| Variable | Purpose | Notes |
|---|---|---|
| `DB_*` | MySQL connection | `mysql` driver required; the schema uses `FULLTEXT` and `JSON` columns |
| `CACHE_STORE` | Cache backend | `redis` locally; set to `database` on hosts without Redis |
| `QUEUE_CONNECTION` | Queue backend | `redis` locally; `database` also works. Horizon requires Redis |
| `GEMINI_API_KEY` | LLM provider key | **Never commit this.** `.env` is gitignored; `.env.example` ships blank. Leave it empty and the app falls back to `FakeProvider` |
| `GEMINI_MODEL` | Model id | Defaults to `gemini-3.6-flash`. Run `php artisan ai:models` to list what your key can actually reach |
| `AI_PROVIDER` | Force a provider | `gemini` or `fake`. Auto-detected from the key if unset |
| `AI_MAX_ATTEMPTS` | Repair attempts | Default 3: one call plus two corrections |

Switching off Redis is a two-variable change with no code edits.

---

## Architecture

### The one idea that shapes everything

**A form's identity and a form's shape are stored separately.**

`forms` holds identity: the owner, the title, and the slug that makes up the public URL.
`form_versions` holds shape: an immutable, append-only JSON snapshot of every field.
`forms.current_version_id` points at whichever snapshot is live.

Editing a form never mutates a version — it writes the next one. That single decision buys three
things that would each otherwise need their own mechanism:

1. **The JSON schema really is the single source of truth.** The canvas, the raw JSON editor, the
   public renderer and the server-side validator all read `form_versions.schema`. There is no
   normalised `form_fields` table that could silently drift out of sync with it.
2. **Old submissions stay readable.** Every submission stores the `form_version_id` it was filled
   against. Delete a field today and yesterday's responses still render with the labels the
   respondent actually saw.
3. **Versioning and rollback are free.** "Restore v3" is repointing `current_version_id`. No
   destructive edit, no data loss, and a complete audit trail of who changed what.

### Request flow

```
Builder (authenticated)                   Public (anonymous)
──────────────────────                    ──────────────────
/forms            FormController          /f/{slug}   PublicFormController
/forms/create     FormDetails (Livewire)      │
       │                                      ├─ 404 unless status = published
       ├─ validate schema                     ├─ load forms.current_version_id
       ├─ checksum: changed?                  └─ render fields from that snapshot
       └─ write next form_version
          repoint current_version_id
```

### Directory map

| Path | Contains |
|---|---|
| `app/Enums/FieldType.php` | The 17 field types. Single allow-list used by the palette, validator, AI prompt, importer and renderer |
| `app/Enums/FormStatus.php` | Draft / Published / Archived, and which of them accept submissions |
| `app/Models/` | `Form`, `FormVersion`, `FormSubmission`, `FormSubmissionFile`, `AiGeneration`, `DocumentImport` |
| `app/Observers/` | Maintains `search_text` and `forms.submission_count` on write |
| `app/Forms/` | The schema contract: validator, normaliser, reconciler, rule compiler |
| `app/Services/Ai/` | Provider abstraction, prompts, the repair loop |
| `app/Services/Import/` | Deterministic parsers, the type guesser, AI refinement |
| `app/Livewire/Forms/` | Builder wizard screens |
| `app/Livewire/Imports/` | Upload and the mapping screen |
| `database/samples/` | Sample import documents, regenerated by `import:samples` |
| `resources/views/public/` | The public renderer, driven entirely by a schema snapshot |
| `public/theme/` | Pre-compiled vendor admin theme, kept out of the Vite bundle on purpose |

---

## Database schema

Six domain tables. Full column-by-column reasoning is in the migration docblocks.

```
users
  └── forms ──────────────┬── form_versions (immutable snapshots; schema JSON lives here)
       │                  │        ▲
       │                  │        └── forms.current_version_id points at the live one
       │                  │
       ├── form_submissions ── form_version_id  (which schema this was answered against)
       │        └── form_submission_files
       │
       ├── ai_generations   (Part B: prompt, model, tokens, latency, status)
       └── document_imports (Part C: parsed vs proposed schema, warnings)
```

### Indexes, and why each one exists

| Table | Index | Query it serves |
|---|---|---|
| `forms` | `(user_id, status, created_at)` | The dashboard. Filter by owner + status and sort by date from one index, no filesort |
| `forms` | `(status, published_at)` | Listing live forms |
| `forms` | `slug` unique | Public URL resolution, and slug collision checks |
| `form_versions` | `(form_id, version_number)` unique | Enforces the monotonic sequence; serves "fetch v3 of form 12" |
| `form_versions` | `(form_id, created_at)` | Listing a form's versions newest first |
| `form_submissions` | `(form_id, submitted_at)` | **The hottest query in the app** — one form's responses, newest first, paginated |
| `form_submissions` | `(form_id, status)` | Filtering complete vs partial responses |
| `form_submissions` | `FULLTEXT(search_text)` | The submissions search box |
| `form_submission_files` | `(form_submission_id, field_key)` | Rendering one submission's attachments |
| `ai_generations` | `(user_id, created_at)`, `(status, created_at)`, `(model, created_at)` | "My generations", stuck-job sweep, per-model cost reporting |
| `document_imports` | `(user_id, status)`, `(status, created_at)` | Resume-where-I-left-off, and the stuck-job sweep |

**Two denormalised columns**, both rebuildable, both maintained by `FormSubmissionObserver`:

- `forms.submission_count` — avoids a `COUNT(*)` per row on the dashboard. Written with an atomic
  `increment()` so concurrent submissions cannot clobber each other.
- `form_submissions.search_text` — MySQL cannot `FULLTEXT` index a JSON column, and
  `WHERE data->>'$.x' LIKE '%term%'` is a full table scan. Every answer value is flattened into
  this one column on write so search has a real index.

---

## Implementation status

### Done

**256 tests, 525 assertions, all passing.** Run with `./vendor/bin/pest`.

#### Part A — Core form builder (complete)

- Drag & drop **and** click-to-add; reorder within and across sections, duplicate, inline edit, delete
- **17 field types** (brief asks for 10), groupable into sections or wizard steps
- Per-field config: label, key, placeholder, help, default, required, options manager, validation rules
- Raw JSON editor with two-way canvas sync, normalised and validated before it can replace the canvas
- Clean indexed MySQL schema (see the index table above)
- Public fill URL, server-side validation compiled from the stored schema version, honeypot, rate limiting
- Submissions with file uploads, paginated list, FULLTEXT search, streamed CSV export

#### Part B — AI generation (complete)

- Natural-language prompt to a complete, fully editable form
- **AI editing of an existing form** — the AI tab inside the builder
- Queued job with visible status; the web request never waits on the model
- Model, tokens, latency and attempts logged to `ai_generations`
- Repair-and-retry loop; a broken schema is never persisted
- Works with no API key at all via `FakeProvider`

#### Part C — Word & Excel import (complete)

- `.docx` → headings become sections, questions become fields, bullet and checkbox
  lists become that question's options, two-column tables are read as label/answer
- `.xlsx` → **two documented layouts**, auto-detected (see below)
- **Hybrid**: deterministic parse first, AI asked only about fields it could not classify
- Preview and mapping screen before anything is committed, with per-field type correction
- Queued; unparseable blocks reported as warnings rather than dropped
- Four sample documents committed in [database/samples/](database/samples/)



---

## Routes

There is no JSON API — the application is server-rendered with Livewire, so these are the HTTP
endpoints in full. `php artisan route:list --except-vendor` prints the same list.

### Public — no authentication

| Method | URI | Purpose | Limit |
|---|---|---|---|
| `GET` | `/f/{slug}` | Render a published form from its live schema version | 60/min per IP |
| `POST` | `/f/{slug}` | Accept a submission | 10/min per IP |
| `GET` | `/f/{slug}/thanks` | Confirmation page after submitting | 60/min per IP |

An unpublished form returns **404, not 403**, so a stranger cannot confirm a slug exists.

### Authentication

| Method | URI | Purpose | Limit |
|---|---|---|---|
| `GET` | `/login` | Sign-in form | — |
| `POST` | `/login` | Attempt sign-in | 5/min per IP |
| `POST` | `/logout` | Sign out | — |

There is no registration route: the demo account is seeded, and open sign-up on a public demo is an
invitation to spam.

### Builder — authenticated, scoped to the owner

| Method | URI | Purpose |
|---|---|---|
| `GET` | `/forms` | Dashboard: search, status filter, pagination |
| `GET` | `/forms/create` | Wizard step 1 — form details |
| `GET` | `/forms/{form}/details` | Wizard step 1 for an existing form |
| `GET` | `/forms/{form}/build` | Wizard step 2 — canvas, field options, JSON editor, AI edit |
| `GET` | `/forms/{form}/settings` | Wizard steps 3–4 — settings and publish |
| `DELETE` | `/forms/{form}` | Soft delete |
| `GET` | `/forms/ai` | Generate a form from a prompt (20/min) |
| `GET` | `/imports` | Upload a `.docx` / `.xlsx` |
| `GET` | `/imports/{import}/review` | Preview and mapping screen |

### Responses

| Method | URI | Purpose |
|---|---|---|
| `GET` | `/forms/{form}/responses` | Paginated list with FULLTEXT search |
| `GET` | `/forms/{form}/responses/export` | Streamed CSV, honours the search term |
| `GET` | `/forms/{form}/responses/{submission}` | One response, rendered against its own schema version |
| `GET` | `/forms/{form}/responses/{submission}/files/{file}` | Download an upload |
| `DELETE` | `/forms/{form}/responses/{submission}` | Delete a response and its files |

Every builder and response route checks `form.user_id` against the signed-in user and returns 403
otherwise. Uploads are served through the app rather than from a public directory, so an attachment
cannot be reached by guessing a filename.

---

## Tests

```bash
./vendor/bin/pest                    # everything
./vendor/bin/pest --testsuite=Unit   # schema layer only, no database
```

**256 tests, 525 assertions.** Three suites, and the split is deliberate:

| Suite | Database | Covers |
|---|---|---|
| `Unit` | none | `SchemaValidator`, `SchemaNormaliser`, `RuleCompiler`, `SchemaReconciler`, the import parsers |
| `Feature` | transaction, rolled back | Livewire components, controllers, jobs, whole-page renders |
| `Integration` | **truncation, committed** | Submission search |

Integration exists for one concrete reason: **InnoDB does not update a `FULLTEXT` index until the
writing transaction commits.** `RefreshDatabase` wraps each test in a transaction it rolls back, so
`MATCH … AGAINST` finds nothing the test just inserted — search tests would fail while the feature
worked perfectly in production. Those tests truncate instead, so the index is real.

Tests requiring the sample documents skip cleanly if `php artisan import:samples` has not been run.

The AI suite runs entirely against `FakeProvider`: offline, free, and able to inject malformed
responses on demand so the repair loop is exercised rather than assumed.

---

## Excel import layouts

Both are detected automatically from the header row.

### Layout A — field definition sheet

One row per field. Detected when the header contains at least two of
*Label/Question*, *Type*, *Required*, *Options*.

| Section | Label | Type | Required | Options | Help |
|---|---|---|---|---|---|
| Attendee | Full name | text | yes | | |
| Attendee | T-shirt size | dropdown | yes | `XS\|S\|M\|L\|XL` | |
| Sessions | Which sessions? | checkbox | no | `Keynote\|Workshop A` | Select all that apply |

- **Section** groups fields; blank falls back to "Details".
- **Type** accepts any `FieldType` value plus the normaliser's aliases (`select`,
  `multiline`, `e-mail`…). An unrecognised type is warned about and guessed from
  the label instead of failing.
- **Options** are split on `|` first, then on commas.
- **Required** accepts yes/y/true/1/x.

Every type is explicit, so **no AI call is made at all** for this layout.

### Layout B — plain data sheet

A normal spreadsheet of records. Row 1 becomes the field names; row 2 is read as
sample values to settle each type.

| Full Name | Contact | Joined On | Department |
|---|---|---|---|
| Priya Sharma | priya@example.com | 2024-06-01 | Engineering |

- `priya@example.com` → email, `2024-06-01` → date, numeric → number.
- A column whose values **repeat** becomes a dropdown; a column where every value is
  distinct stays free text.

---

## AI prompt strategy

Provider: **Google Gemini**, model `gemini-3.6-flash`, via the stateless `generateContent`
endpoint. Everything below is in [app/Services/Ai/](app/Services/Ai/).

### Output contract

The model is not asked to return JSON — it is **structurally prevented from returning anything
else**. Every call sets `responseMimeType: application/json` plus a `responseSchema`, and that
schema's field-`type` property is an `enum` built directly from `FieldType::values()`:

```php
'type' => ['type' => 'string', 'enum' => FieldType::values()],
```

One line, and it is the whole hallucinated-field-type defence. The enum that drives the builder
palette, the validator, the renderer and the importer is the same list the model is constrained
to. They cannot drift, because there is only one of them.

### The four layers

`responseSchema` guarantees *shape*, not *sense* — it can still return a well-formed schema with
duplicate keys or options on a text field. So:

| Layer | Catches |
|---|---|
| 1. `responseSchema` | Prose, code fences, unknown field types |
| 2. `SchemaNormaliser` | Aliases (`select`→`dropdown`), bare-string options, missing keys/ids, media types where extensions belong |
| 3. `SchemaValidator` | Duplicate keys, options on a text field, impossible ranges, uncompilable regex |
| 4. Repair loop | Whatever survived — errors fed back verbatim, up to `AI_MAX_ATTEMPTS` (default 3) |

**Normalise before validate** is the key ordering decision. A model writing `"select"` instead of
`"dropdown"` is not wrong in a way worth a 20-second round trip; that is mechanically repairable.
Only genuine mistakes reach the retry, so the retry budget is spent on real problems.

### Handling hallucinated field types

An invented type never reaches the database. It fails `SchemaValidator`, and the repair prompt
names both the offending value and the legal set:

```
1. sections.0.fields.2.type: Unknown field type "signature_pad".
   Must be one of: text, textarea, email, phone, url, number, date, time,
   datetime, dropdown, radio, checkbox, rating, file, heading, paragraph, divider.
```

The previous (invalid) output is included alongside the errors. Without it the model is being
asked to fix something it cannot see, and tends to start over from the original prompt instead of
correcting.

### Retries and fallbacks

- **Transport errors** (429, 5xx, timeouts) retry with linear backoff. A 4xx does not — the same
  request will fail identically.
- **Truncated responses** (`finishReason: MAX_TOKENS`) are reported as truncation, not as invalid
  JSON, so the fix is "allow more output tokens" rather than "the model is broken".
- **No key configured** falls back to `FakeProvider`, which returns canned but schema-valid forms.
  The demo works with zero setup and the whole test suite runs offline.
- **After the last attempt**, the generation is marked failed with the last validation errors, and
  nothing is written.

### Never persisting a broken schema

`ai_generations.result_schema` is only written *after* validation passes, and `form_versions` is
only ever written from a validated `result_schema`. A failed generation leaves the form untouched.

### Editing an existing form

The brief asks for AI editing, not just creation. This is the ✨ AI tab in the builder, and it
required a fix the prompt alone could not deliver — see decision 11 in [DECISIONS.md](DECISIONS.md).
Short version: asked to add a section, Gemini returned a valid form with `key` dropped from fields
it had kept; the normaliser then derived new keys from labels, and **4 of 9 keys changed**, which
would have orphaned every stored answer. `SchemaReconciler` now matches every generated field back
to its original by id, then key, then label, and restores identity in code. Verified against the
live model: 9/9 keys preserved.

### Observability

Every call writes one `ai_generations` row: prompt, provider, model, status, attempts,
`input_tokens`, `output_tokens`, `latency_ms`, the raw response, and the validated result. That row
is *also* the status the browser polls, so there is no second job-tracking mechanism to keep in
sync. Recent generations with their token and latency figures are shown on the AI page.

Measured on real calls: a create runs 6–22s and 1,700–6,900 tokens; an edit of a 9-field form runs
~17s and ~3,900 tokens.

---

## Credits

Bootstrap · Livewire · SortableJS · PhpWord · PhpSpreadsheet · Laravel Horizon.
The admin theme chrome (sidebar, icons, page header) was supplied with the assignment brief.
