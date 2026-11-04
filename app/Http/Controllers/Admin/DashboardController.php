<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesCurrentApartment;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\HandoverRequest;
use App\Models\UnitInventory;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use ResolvesCurrentApartment;

    /** How far ahead the occupancy figure looks. */
    private const OCCUPANCY_DAYS = 30;

    /** How far ahead confirmed move-ins are listed. */
    private const ARRIVAL_DAYS = 7;

    /** A pending request older than this counts as a guest left waiting. */
    private const WAITING_HOURS = 24;

    public function index(Request $request): View
    {
        $apartment = $this->currentApartment($request);

        $today = now($apartment->timezone)->startOfDay();

        $occupancy = UnitInventory::whereIn('unit_type_id', $apartment->unitTypes()->where('is_active', true)->select('id'))
            ->whereDate('stay_date', '>=', $today->toDateString())
            ->whereDate('stay_date', '<=', $today->copy()->addDays(self::OCCUPANCY_DAYS - 1)->toDateString())
            ->selectRaw('SUM(total_units) as total, SUM(booked_units) as booked')
            ->first();

        $stats = [
            'unit_types' => $apartment->unitTypes()->count(),
            'knowledge_items' => $apartment->knowledgeItems()->count(),
            'pending_bookings' => $apartment->bookings()->where('status', Booking::STATUS_PENDING)->count(),
            'waiting_bookings' => $apartment->bookings()
                ->where('status', Booking::STATUS_PENDING)
                ->where('created_at', '<', now()->subHours(self::WAITING_HOURS))
                ->count(),
            'arrivals' => $apartment->bookings()
                ->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_DEPOSIT_PAID])
                ->whereDate('check_in', '>=', $today->toDateString())
                ->whereDate('check_in', '<=', $today->copy()->addDays(self::ARRIVAL_DAYS - 1)->toDateString())
                ->count(),
            'occupancy_percent' => $occupancy?->total > 0 ? (int) round($occupancy->booked / $occupancy->total * 100) : null,
            'open_handovers' => HandoverRequest::whereHas('conversation', fn ($q) => $q->where('apartment_id', $apartment->id))
                ->where('status', HandoverRequest::STATUS_OPEN)
                ->count(),
        ];

        $arrivals = $apartment->bookings()
            ->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_DEPOSIT_PAID])
            ->whereDate('check_in', '>=', $today->toDateString())
            ->whereDate('check_in', '<=', $today->copy()->addDays(self::ARRIVAL_DAYS - 1)->toDateString())
            ->with('unitType')
            ->orderBy('check_in')
            ->get();

        $recentBookings = $apartment->bookings()->latest()->take(5)->with('unitType')->get();

        $openHandovers = HandoverRequest::whereHas('conversation', fn ($q) => $q->where('apartment_id', $apartment->id))
            ->where('status', HandoverRequest::STATUS_OPEN)
            ->latest()
            ->take(5)
            ->with('conversation')
            ->get();

        return view('admin.dashboard', [
            'apartment' => $apartment,
            'stats' => $stats,
            'arrivals' => $arrivals,
            'recentBookings' => $recentBookings,
            'occupancyDays' => self::OCCUPANCY_DAYS,
            'arrivalDays' => self::ARRIVAL_DAYS,
            'waitingHours' => self::WAITING_HOURS,
            'openHandovers' => $openHandovers,
        ]);
    }
}
