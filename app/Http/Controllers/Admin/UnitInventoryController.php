<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesCurrentApartment;
use App\Http\Controllers\Controller;
use App\Models\UnitInventory;
use App\Models\UnitType;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Where staff open dates for rent and set how many units of a type can be
 * leased. The concierge and the rental application wizard only ever read
 * this table.
 */
class UnitInventoryController extends Controller
{
    use ResolvesCurrentApartment;

    /** The longest range one submit may open, so a typo cannot create years of rows. */
    private const MAX_RANGE_DAYS = 366;

    public function index(Request $request, UnitType $unitType): View
    {
        $apartment = $this->currentApartment($request);
        abort_if($unitType->apartment_id !== $apartment->id, 404);

        $month = $this->month($request, $apartment->timezone);

        $inventory = $unitType->inventory()
            ->whereDate('stay_date', '>=', $month->toDateString())
            ->whereDate('stay_date', '<=', $month->endOfMonth()->toDateString())
            ->get()
            ->keyBy(fn (UnitInventory $night) => $night->stay_date->toDateString());

        return view('admin.unit-types.inventory', [
            'apartment' => $apartment,
            'unitType' => $unitType,
            'month' => $month,
            'days' => CarbonPeriod::create($month, $month->endOfMonth()),
            'inventory' => $inventory,
            'today' => now($apartment->timezone)->toDateString(),
        ]);
    }

    public function store(Request $request, UnitType $unitType): RedirectResponse
    {
        $apartment = $this->currentApartment($request);
        abort_if($unitType->apartment_id !== $apartment->id, 404);

        $today = now($apartment->timezone)->toDateString();

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'total_units' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        if (CarbonImmutable::parse($data['from'])->diffInDays($data['to']) >= self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Open at most '.self::MAX_RANGE_DAYS.' days at a time.']);
        }

        $range = CarbonPeriod::create($data['from'], $data['to']);

        DB::transaction(function () use ($unitType, $data, $range) {
            $existing = $unitType->inventory()
                ->whereDate('stay_date', '>=', $data['from'])
                ->whereDate('stay_date', '<=', $data['to'])
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (UnitInventory $night) => $night->stay_date->toDateString());

            if ($existing->contains(fn (UnitInventory $night) => $night->booked_units > $data['total_units'])) {
                throw ValidationException::withMessages([
                    'total_units' => 'Some dates in that range already have more units booked than this. Cancel those bookings first, or choose a higher number.',
                ]);
            }

            foreach ($range as $date) {
                $night = $existing->get($date->toDateString());

                if ($night) {
                    $night->update([
                        'total_units' => $data['total_units'],
                    ]);

                    continue;
                }

                $unitType->inventory()->create([
                    'stay_date' => $date->toDateString(),
                    'total_units' => $data['total_units'],
                    'booked_units' => 0,
                ]);
            }
        });

        return redirect()
            ->route('admin.unit-types.inventory.index', [$unitType, 'month' => substr($data['from'], 0, 7)])
            ->with('status', "Inventory for \"{$unitType->name}\" updated from {$data['from']} to {$data['to']}.");
    }

    private function month(Request $request, string $timezone): CarbonImmutable
    {
        $requested = $request->query('month');

        if (is_string($requested) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested)) {
            return CarbonImmutable::createFromFormat('Y-m-d', $requested.'-01', $timezone)->startOfDay();
        }

        return now($timezone)->toImmutable()->startOfMonth();
    }
}
