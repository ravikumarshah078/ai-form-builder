<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\SubmissionController;
use App\Livewire\Forms\AiGenerate;
use App\Livewire\Forms\Builder;
use App\Livewire\Forms\FormDetails;
use App\Livewire\Forms\Settings;
use App\Livewire\Imports\Review as ImportReview;
use App\Livewire\Imports\Upload as ImportUpload;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Three groups, deliberately separated:
|
|   1. Guest      - login only.
|   2. Builder    - everything behind auth. All of it is scoped to the signed
|                   in user, so one account can never reach another's forms.
|   3. Public     - the fill URL. No auth, no session requirement, and rate
|                   limited, because this is the one surface strangers touch.
|
*/

// ── 1. Guest ─────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// ── 2. Builder (authenticated) ───────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::redirect('/', '/forms');

    Route::get('/forms', [FormController::class, 'index'])->name('forms.index');
    Route::delete('/forms/{form}', [FormController::class, 'destroy'])->name('forms.destroy');

    // The wizard. Each step is a full-page Livewire component, so there is no
    // controller in between and each step owns its own state.
    // Part B: generate a whole form from a prompt. Rate limited because each
    // submission costs real provider tokens.
    Route::get('/forms/ai', AiGenerate::class)
        ->middleware('throttle:20,1')
        ->name('forms.ai');

    // Part C: import from Word / Excel.
    Route::get('/imports', ImportUpload::class)->name('imports.create');
    Route::get('/imports/{import}/review', ImportReview::class)->name('imports.review');

    Route::get('/forms/create', FormDetails::class)->name('forms.create');
    Route::get('/forms/{form}/details', FormDetails::class)->name('forms.details');
    Route::get('/forms/{form}/build', Builder::class)->name('forms.build');
    Route::get('/forms/{form}/settings', Settings::class)->name('forms.settings');

    // Responses.
    Route::prefix('/forms/{form}')->group(function () {
        Route::get('/responses', [SubmissionController::class, 'index'])
            ->name('forms.submissions');

        // Declared before the {submission} route so "export" is never
        // mistaken for a submission uuid.
        Route::get('/responses/export', [SubmissionController::class, 'export'])
            ->name('forms.submissions.export');

        Route::get('/responses/{submission}', [SubmissionController::class, 'show'])
            ->name('forms.submissions.show');

        Route::get('/responses/{submission}/files/{file}', [SubmissionController::class, 'file'])
            ->name('forms.submissions.file');

        Route::delete('/responses/{submission}', [SubmissionController::class, 'destroy'])
            ->name('forms.submissions.destroy');
    });
});

// ── 3. Public fill URL ───────────────────────────────────────────────────
// Rate limited per IP. A published form is world-readable by design, so this
// is the surface that needs a ceiling on abuse. The POST limit is tighter than
// the GET limit: reading a form is cheap, writing a row is not.
Route::group([], function () {
    Route::get('/f/{form:slug}', [PublicFormController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('public.form.show');

    Route::post('/f/{form:slug}', [PublicFormController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('public.form.submit');

    Route::get('/f/{form:slug}/thanks', [PublicFormController::class, 'success'])
        ->middleware('throttle:60,1')
        ->name('public.form.success');
});
