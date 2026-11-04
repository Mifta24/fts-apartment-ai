<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesCurrentApartment;
use App\Http\Controllers\Controller;
use App\Models\ViewingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ViewingController extends Controller
{
    use ResolvesCurrentApartment;

    public function index(Request $request): View
    {
        $apartment = $this->currentApartment($request);

        $status = in_array($request->query('status'), ViewingRequest::STATUSES, true)
            ? $request->query('status')
            : null;

        $viewings = $apartment->viewingRequests()
            ->with('unitType')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.viewings.index', compact('apartment', 'viewings', 'status'));
    }

    public function updateStatus(Request $request, ViewingRequest $viewing): RedirectResponse
    {
        $apartment = $this->currentApartment($request);
        abort_if($viewing->apartment_id !== $apartment->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(ViewingRequest::STATUSES)],
        ]);

        if (! $viewing->canTransitionTo($data['status'])) {
            return back()->with('error', "Viewing {$viewing->reference} is {$viewing->status} and cannot be marked as {$data['status']}.");
        }

        $viewing->update(['status' => $data['status']]);

        return back()->with('status', "Viewing {$viewing->reference} marked as {$data['status']}.");
    }
}
