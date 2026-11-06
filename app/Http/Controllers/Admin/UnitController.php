<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesCurrentApartment;
use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The numbered apartments in the building: which are free, taken until when.
 */
class UnitController extends Controller
{
    use ResolvesCurrentApartment;

    public function index(Request $request): View
    {
        $apartment = $this->currentApartment($request);

        $unitTypes = $apartment->unitTypes()
            ->with(['units' => fn ($query) => $query->orderBy('floor')->orderBy('unit_number')])
            ->orderBy('sort_order')
            ->get();

        return view('admin.units.index', compact('apartment', 'unitTypes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $apartment = $this->currentApartment($request);
        $data = $this->validated($request, $apartment);

        $apartment->units()->create($data);

        return back()->with('status', "Apartment {$data['unit_number']} added.");
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $apartment = $this->currentApartment($request);
        abort_if($unit->apartment_id !== $apartment->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(Unit::STATUSES)],
            'available_from' => ['nullable', 'date'],
            'facing' => ['nullable', Rule::in(Unit::FACINGS)],
        ]);

        $unit->update($data);

        return back()->with('status', "Apartment {$unit->unit_number} updated.");
    }

    public function destroy(Request $request, Unit $unit): RedirectResponse
    {
        $apartment = $this->currentApartment($request);
        abort_if($unit->apartment_id !== $apartment->id, 404);

        $unit->delete();

        return back()->with('status', "Apartment {$unit->unit_number} removed.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Apartment $apartment): array
    {
        return $request->validate([
            'unit_type_id' => ['required', Rule::exists('unit_types', 'id')->where('apartment_id', $apartment->id)],
            'unit_number' => ['required', 'string', 'max:12', Rule::unique('units', 'unit_number')->where('apartment_id', $apartment->id)],
            'floor' => ['required', 'integer', 'min:0', 'max:200'],
            'facing' => ['nullable', Rule::in(Unit::FACINGS)],
            'status' => ['required', Rule::in(Unit::STATUSES)],
            'available_from' => ['nullable', 'date'],
        ]);
    }
}
