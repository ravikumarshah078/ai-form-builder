<?php

namespace App\Http\Controllers;

use App\Models\Form;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FormController extends Controller
{
    /**
     * The forms dashboard.
     *
     * The query is shaped to hit forms_owner_listing_idx (user_id, status,
     * created_at): filter by owner, optionally by status, then sort by
     * created_at. MySQL can serve all three from that one index rather than
     * filesorting.
     *
     * `submission_count` is read from the denormalised column rather than
     * withCount('submissions'), which would add a correlated subquery per row.
     */
    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();

        $forms = Form::query()
            ->ownedBy($request->user()->id)
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->where('title', 'like', '%'.$term.'%');
            })
            ->latest('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('forms.index', compact('forms', 'status'));
    }

    /**
     * Soft delete. The form keeps its slug reserved (the uniqueness check in
     * Form::generateUniqueSlug looks at trashed rows too), so a restored form
     * still resolves at the URL that may already be printed on a poster.
     */
    public function destroy(Request $request, Form $form): RedirectResponse
    {
        abort_unless($form->user_id === $request->user()->id, 403);

        $form->delete();

        return redirect()
            ->route('forms.index')
            ->with('success', "\"{$form->title}\" was deleted.");
    }
}
