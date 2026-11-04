<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesCurrentApartment;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Reservation\ReservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BookingController extends Controller
{
    use ResolvesCurrentApartment;

    public function __construct(private readonly ReservationService $reservations) {}

    public function index(Request $request): View
    {
        $apartment = $this->currentApartment($request);

        $status = in_array($request->query('status'), Booking::STATUSES, true)
            ? $request->query('status')
            : null;

        $bookings = $apartment->bookings()
            ->with('unitType')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.bookings.index', compact('apartment', 'bookings', 'status'));
    }

    public function updateStatus(Request $request, Booking $booking): RedirectResponse
    {
        $apartment = $this->currentApartment($request);
        abort_if($booking->apartment_id !== $apartment->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_diff(Booking::STATUSES, [Booking::STATUS_PENDING]))],
        ]);

        if (! $this->reservations->changeStatus($booking, $data['status'])) {
            return back()->with('error', "Application {$booking->reference} is {$booking->statusLabel()} and cannot be marked as ".Booking::statusName($data['status']).'.');
        }

        return back()->with('status', "Application {$booking->reference} marked as ".Booking::statusName($data['status']).'.');
    }
}
