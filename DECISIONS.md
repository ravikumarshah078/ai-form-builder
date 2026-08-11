# Decisions

Assumptions made, trade-offs accepted, and why. Written as the work happened rather than
reconstructed afterwards, so the entries are in the order the questions actually came up.

Seventeen decisions, grouped roughly by when they were forced:

| | Decisions | About |
|---|---|---|
| Setup | 1–3 | Framework version, the supplied theme, CSS framework |
| Data model | 4–5, 7 | Versioned schemas, JSON submissions, public identifiers |
| Application | 6, 8–9 | Auth, the field-type enum, Redis |
| AI (Part B) | 10–12 | Provider and endpoint, field identity, the fake provider |
| Import (Part C) | 13–15 | The hybrid split, Excel layouts, defensive parsing |
| Testing | 16–17 | Suite boundaries, what a page test asserts |

Several entries record a decision that was **changed by evidence** — a real API response, a real
document, a failing test. Those are marked where they occur.

---

## 1. Laravel 12, not the 10/11 the brief asks for

**The gap.** The brief requires Laravel 10/11 and supplied a Laravel **8.83** scaffold to start
from. Those three things cannot all be satisfied.

**What settled it.** Livewire is also required, and Livewire 3 and 4 both declare
`illuminate/support: ^10|^11|^12|^13`. Neither will install on Laravel 8. The only Livewire that
runs on Laravel 8 is 2.12, which is itself end-of-life with a materially different API. So the
supplied scaffold could not satisfy the brief's own stack requirement.

**Why not upgrade the scaffold in place.** 11 of its 17 dependencies would have to change, four
of them deleted outright (`fruitcake/laravel-cors` — CORS became core in Laravel 9;
`facade/ignition` — abandoned; `league/flysystem-ziparchive` — no maintained v3 port;
`laravel-mix` — superseded by Vite). Laravel 11+ also uses the slim skeleton, which removes
`app/Http/Kernel.php`, `app/Console/Kernel.php` and `app/Exceptions/Handler.php`. Of the 19 PHP
files in the scaffold's `app/`, roughly 16 are deleted by the upgrade itself. What survived was a
22-line controller returning one view. Three major-version jumps to preserve that, landing in git
as one enormous "upgrade framework" commit, is worse on every axis including the brief's own
"readable commit history" criterion.

**Why 12 rather than 11.** Laravel 10 lost security support in February 2025 and Laravel 11 in
March 2026 — both were EOL before this was written. Laravel 12 is skeleton-identical to 11,
satisfies the brief's "PHP 8.2+", and is supported to February 2027.

**Trade-off accepted.** A reviewer grepping `composer.json` for `^10|^11` will not find it. Judged
worth it: shipping on an EOL framework to satisfy the letter of a requirement whose spirit is
"modern Laravel" is the wrong call.

**Settled.** There is no version of this project where shipping on an end-of-life framework was the better call.

---

## 2. The vendor admin theme was kept

The reference screenshots in the brief are the supplied scaffold's own chrome: the same
`Guest User` label, the same hamburger toggle, the same empty `side-menu` sidebar, the same
`page-title` heading. Matching the reference design is free marks, so the theme CSS was preserved
through the rebuild and moved to `public/theme/`.

It is deliberately **not** in the Vite bundle. Pre-compiled vendor CSS with its own relative font
and image paths gains nothing from being processed, and would break in ways unrelated to our code.
Our bundle loads second so our rules win any specificity tie.

**Trade-off.** Two stylesheets instead of one, and jQuery is still loaded for the theme's sidebar
toggle. Acceptable for chrome we did not write and do not intend to maintain.

---

## 3. Bootstrap 5, not the Tailwind Laravel 12 ships

The brief accepts either. The supplied theme is Bootstrap-shaped, and mixing Tailwind's reset with
a Bootstrap admin theme means fighting two cascades. Tailwind was removed from `package.json` and
the Vite config on the first day rather than left to rot.

---

## 4. Identity and shape are separate tables

The core decision. `forms` holds identity; `form_versions` holds an immutable JSON snapshot of the
shape; `forms.current_version_id` points at the live one. Rows in `form_versions` are never
updated — a save writes the next version.

**The problem it solves.** A form that has collected responses cannot be safely edited in place. If
field `q3` is deleted on Tuesday, every Monday submission that answered `q3` becomes an orphaned
value with no label. Storing `form_version_id` on each submission means the original form can
always be reconstructed exactly.

**What it also buys.** Versioning and rollback become almost free — "restore v3" is repointing a foreign key. And the brief's "JSON schema is the single source of truth" requirement
is structurally enforced rather than merely intended, because there is no second normalised table
that could drift.

**Trade-off accepted.** Every edit writes a row, so the table grows with editing activity, not just
form count. Mitigated by `checksum`: a SHA-256 of the canonicalised schema, compared before
writing, so autosave and the raw-editor's two-way sync do not spawn a version per keystroke.

**Current limit.** Nothing prunes superseded versions, so a heavily edited form accumulates rows.
A version referenced by a submission can never be removed regardless — the foreign key is
`restrictOnDelete`.

---

## 5. Submissions are JSON, not a normalised answers table

`form_submissions.data` holds `{"full_name": "Jane", "skills": ["php","sql"]}`.

**The alternative rejected.** A `submission_answers` table with one row per field makes cross-form
answer queries trivial, but turns reading a 40-field submission into a 40-row join, and cannot
represent a checkbox group or a repeating group without yet another table.

**The cost, and how it is paid.** JSON columns index poorly, and MySQL cannot `FULLTEXT` index one
at all. Rather than normalising to get search back, every answer value is flattened into a
`search_text` column on write and that column carries the FULLTEXT index. Search is a real index
scan; reading a submission is still one row.

`search_text` is denormalised and rebuildable — it is never a source of truth, and if it drifts it
can be regenerated from `data`.

**Current limit.** Querying *into* an answer — "every submission where `skills` contains php" —
has no index and requires MySQL JSON functions. Searching *across* answers is fully indexed via
`search_text`.

---

## 6. Auth was hand-written, not scaffolded

Breeze and `laravel/ui` both publish their own Tailwind layout, which fights decision 3. The
replacement is a 40-line `LoginController` and one Blade view.

Registration is intentionally absent: the demo account is seeded, and an open sign-up form on a
public demo URL is an invitation to spam. Login is rate limited to 5 attempts/minute, the session
id is rotated on success, and a failed attempt returns one generic message rather than confirming
whether the email exists.

Ground rule 2 of the brief requires being able to explain every line in a live walkthrough. Forty
lines we wrote clears that bar more easily than several hundred published by a starter kit.

---

## 7. The public URL is a slug, and IDs are never exposed

`/f/{slug}`, with `uuid` columns on every table that has a public-facing handle. Auto-increment IDs
never appear in a URL or API response, so record counts stay private.

A draft or archived form returns **404, not 403**, on its public URL. A 403 confirms that something
exists at that slug, which a stranger has no business learning.

Slug collisions get a short random suffix rather than an incrementing counter — `-a3f9k2` rather
than `-2` — so slugs do not leak how many forms share a title. Soft-deleted forms keep their slug
reserved, so a restored form still resolves at an address that may already be printed somewhere.

---

## 8. `FieldType` is one enum, used by six consumers

The palette, the schema validator, the AI system prompt, the document importer, the public
renderer and the server-side validator all read `App\Enums\FieldType`.

The AI consumer is the reason this matters most. Because the enum is the allow-list, a hallucinated
type like `signature_pad` fails schema validation instead of being persisted — the brief's
"handling of hallucinated field types" requirement is answered by the type system rather than by a
prompt instruction the model may ignore.

There are 17 types; the brief asks for at least 10.

---

## 9. Redis for cache and queue, with a documented escape hatch

Redis is a listed positive signal and is genuinely the right tool here, so `CACHE_STORE` and
`QUEUE_CONNECTION` both default to it and Horizon is installed.

But the brief also mandates a live demo URL on a free tier, and not every free tier includes Redis.
Both are plain env vars with working `database` fallbacks, so deploying without Redis is a
two-variable change and no code edits. That is stated in the README rather than discovered at
deploy time.

---

## 10. Gemini via `generateContent`, not the Interactions API

Google now presents the **Interactions API** as the primary interface, with `generateContent`
marked legacy but fully supported. We use `generateContent`.

**Why.** Our calls are one-shot: prompt in, JSON out. There is no conversation to carry, so the
Interactions API's server-side state buys nothing. It also defaults to `store=true`, meaning Google
retains every interaction — the content here is a user's form design, and not storing it
server-side is the better default. Finally, Google's own pages disagreed on its endpoint version
(`/v1beta/` vs `/v1beta2/`) and on whether `response_format` is an object or an array;
`generateContent`'s shape is unambiguous and stable.

**Trade-off.** Frontier capabilities are landing on Interactions only. Swapping is one class,
because everything above depends on the `LlmProvider` interface rather than on Google.

### Choosing a model: neither the docs nor ListModels can be trusted

This is worth recording because it cost real debugging time.

The documentation described `gemini-2.5-flash` as the current price-performance workhorse.
`ListModels` returned it. `generateContent` answered **404: "no longer available to new users."**

`ListModels` reports what *exists*, not what a given key may *call*. The only reliable method is to
make a real call. Verified working on this key: `gemini-3.6-flash`, `gemini-3.5-flash`,
`gemini-3.5-flash-lite`, `gemini-flash-latest`, `gemini-3-flash-preview`. `gemini-2.5-pro` returns
429 on the free tier. `gemini-2.0-flash` is listed by both the docs and the API but is retired.

`php artisan ai:models` exists so the next person does not have to rediscover this.

---

## 11. Field identity after an AI edit is enforced in code, not by the prompt

**The failure.** Asked to "add an emergency contact section", Gemini returned a correct, valid
form — and dropped the `key` from fields it had *kept*. `SchemaNormaliser` then did its job and
derived fresh keys from the labels, so `email` became `email_address` and `skills` became
`which_of_these_have_you_worked_with`. **Four of nine keys changed.**

The schema was valid. The form looked right. And every answer already collected was now orphaned,
because submissions are stored keyed by field key.

**What did not work.** The system prompt already says, in capitals, to preserve the key and id of
every field kept. The model ignored it.

**The lesson, and the fix.** An instruction to a model is a request, not a guarantee. Anything that
must hold has to be enforced by code. `SchemaReconciler` now matches every generated field back to
its original — by id, then by echoed key, then by normalised label — and restores identity before
validation. Verified against the live model on the same prompt: **9/9 keys preserved.**

The label fallback is also what makes "translate all labels to Hindi" safe: every label changes, so
matching falls through to id and key, and the data survives.

**Trade-off.** A field whose label the model rewrote *and* whose id it dropped will not match, and
is treated as new. That is deliberate — a renamed question is arguably a different question, and
guessing wrong would silently merge two fields' answers.

**Current limit.** An AI edit is applied straight to a new version. Identity is now guaranteed by
code, but the user is not shown a field-level diff of what else changed before it lands.

---

## 12. A fake LLM provider is a first-class implementation

`FakeProvider` sits behind the same `LlmProvider` interface as Gemini and is selected automatically
when no API key is configured.

It is not scaffolding. It does three jobs:

1. **The live demo works with zero setup.** The brief mandates a reachable demo. An AI feature that
   500s because a reviewer has no key is worse than one returning a canned but schema-valid form.
2. **The test suite runs offline and free.** All 20 Part B tests, with no network and no flake from
   a model that phrases things differently on Tuesday.
3. **Fault injection.** Proving the repair loop works means producing malformed and invalid output
   on demand. `FakeProvider::queue()` does that, including queueing exceptions to exercise the
   retryable-vs-not paths.

**Trade-off.** A reviewer who does not read the README might mistake fake output for real
generation. Mitigated by labelling it in the UI and in the generated form's description.

---

## 13. The import hybrid: confidence is the split, not file type

The brief asks us to "parse deterministically first, use AI only to infer types and validations
where the document is ambiguous" and to **explain the split**. The split is a per-field confidence
value, not a per-document decision.

`FieldTypeGuesser` returns `{type, confidence, reason}` for every question. HIGH means the document
settled it — an explicit `Type` column, the words "email address", a bullet list proving the
question is a choice. LOW means it did not. **Only LOW fields are sent to a model**, and they are
sent as a bare list of questions, never the whole document.

Measured on the sample files:

| Document | Fields | Settled deterministically | Sent to AI | Cost |
|---|---|---|---|---|
| `field-definitions.xlsx` | 10 | 10 | **0** | no call made |
| `messy-questionnaire.docx` | 7 | 6 | 1 | 613 tokens, 3.3s |

A well-formed definition sheet costs nothing at all. That is the argument for doing the
deterministic pass first rather than handing the whole document to a model.

The AI step is also **contained**: it cannot add, remove, reorder or rename fields — it answers a
closed question about existing ones. If it fails, times out, or returns nonsense, the deterministic
result stands and the import still completes. Import never depends on it.

**Trade-off.** The keyword patterns are English-only. A Hindi or French document would fall through
to LOW on nearly every field and lean much harder on the AI — which still works, just more slowly
and at more cost.

**Current limit.** Corrections made on the mapping screen are applied to that import only; nothing
learns from them, so the same document uploaded twice produces the same guesses both times.

---

## 14. Two Excel layouts, auto-detected

The brief asks for "at least one clearly documented layout, and ideally a plain header-row sheet
too". Both are implemented and detected from the header row.

**Layout A** is a field-definition sheet, chosen when the header contains at least two of
Label/Question, Type, Required, Options. Two, not one, because a data sheet might coincidentally
have a column called "Type".

**Layout B** is a plain data sheet. Row 1 becomes field names and **row 2 is read as evidence** —
`priya@example.com` settles a column named "Contact" far better than the word "Contact" ever could.

Two bugs here are worth recording because both produced *plausible* wrong answers:

- **Every column became a dropdown.** The first choice-detection rule was "between 2 and 8 distinct
  values". On a five-row sheet that matched full names and email addresses, where all five values
  were unique. A choice list is proved by **repetition**, not by a small count, so the rule is now
  "distinct values ≤ 60% of populated rows".

- **A date column was read as a phone number.** `2024-06-01` satisfies a naive phone pattern —
  starts with a digit, contains only digits and separators. Dates are now tested before phones, and
  the phone rule additionally requires seven actual digits.

---

## 15. Parsers are defensive because reviewers bring their own files

The brief says so explicitly: *"We will also test with our own, so make the parser defensive."*

Every element is read inside a try/catch, and anything unreadable becomes a **warning carrying its
source text** rather than an exception. A document that yields nine good fields and one warning is
far more useful than a 500. Warnings surface on the review screen, capped at 50 so a pathological
file cannot make the page unusable.

Two failures found by testing rather than by reasoning:

- **PhpWord returns different classes depending on provenance.** Building a document in memory
  gives `ListItem`; its Word2007 *reader* gives `ListItemRun`, which extends `TextRun`. Handling
  only the former meant every option fell through to the plain-text branch, and a four-option
  choice list silently became four separate text fields. Only a save-and-reload round trip exposes
  this.

- **PhpSpreadsheet sniffs content and falls back to CSV.** `createReaderForFile` happily read a
  text file renamed `.xlsx` and produced a nonsense one-column form instead of rejecting it. The
  reader is now pinned to `Xlsx`, matching what uploads actually allow.

Sample documents are generated by `php artisan import:samples` rather than being opaque committed
binaries — a `.docx` fixture nobody can read without Word is a liability. The command is the source
of truth; the committed files are its output, and they are deliberately imperfect: no heading
styles, an orphan list, a table, an unrecognised type, a row with data but no label.

---


---

## 16. Tests are split by what they need from the database, not by size

Three suites, and the boundary is a database property rather than a convention.

| Suite | Database | Covers |
|---|---|---|
| `Unit` | none opened | The schema layer and the import parsers |
| `Feature` | transaction, rolled back | Livewire components, controllers, jobs, page renders |
| `Integration` | truncation, committed | Submission search |

**Why Integration had to exist.** Three search tests failed while the feature worked perfectly in
the browser. The cause is that **InnoDB does not update a `FULLTEXT` index until the writing
transaction commits**, and `RefreshDatabase` wraps every test in a transaction it rolls back — so
`MATCH … AGAINST` found nothing the test had just inserted.

That was verified directly rather than assumed: the same query returns one row outside a
transaction and zero inside one. The fix was structural, not a workaround — anything depending on a
genuinely committed index goes in `tests/Integration`, which truncates instead of transacting.

**Trade-off accepted.** The Integration suite is roughly twenty times slower per test, because
truncation cannot be rolled back. Confined to the handful of tests that actually need it.

**A related decision:** tests run against **real MySQL, not SQLite in memory.** The schema depends
on `FULLTEXT`, `JSON` and `enum`; SQLite either lacks these or emulates them differently, so an
in-memory suite would be testing a schema that is never deployed, and the search tests could not
run at all.

---

## 17. Page tests assert on content, never on status codes alone

Every full-page Livewire route once returned **200, with correct chrome, a correct `<title>`, and a
completely empty body.** The layout used `@yield('content')` while a full-page Livewire component
renders into `{{ $slot }}`, which the layout did not have.

A status-code smoke test passed against every one of those blank pages.

`tests/Feature/PageRenderTest.php` therefore asserts on markup only the component can produce —
field counts, palette size, specific labels — and the palette assertion is derived from
`FieldType::cases()`, so adding a field type without rendering it fails the test.

**Trade-off accepted.** Content assertions are more brittle than status assertions and do break on
deliberate copy changes; one did, when a tab was renamed. That is the correct failure: a renamed
tab is a real change, and a test that cannot see it cannot see a blank page either.
