<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesCurrentApartment;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\UnitType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UnitTypeController extends Controller
{
    use ResolvesCurrentApartment;

    public function index(Request $request): View
    {
        $apartment = $this->currentApartment($request);

        $unitTypes = $apartment->unitTypes()->withCount('images')->orderBy('sort_order')->get();

        return view('admin.unit-types.index', compact('apartment', 'unitTypes'));
    }

    public function create(Request $request): View
    {
        $apartment = $this->currentApartment($request);

        return view('admin.unit-types.form', [
            'apartment' => $apartment,
            'unitType' => new UnitType(['category' => UnitType::CATEGORY_RESIDENTIAL, 'listing_type' => UnitType::LISTING_RENT, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $apartment = $this->currentApartment($request);
        $data = $this->validated($request);

        $unitType = $apartment->unitTypes()->create($data);
        $this->syncImages($unitType, $request);

        return redirect()->route('admin.unit-types.index')->with('status', "Unit type \"{$unitType->name}\" created.");
    }

    public function edit(Request $request, UnitType $unitType): View
    {
        $apartment = $this->currentApartment($request);
        abort_if($unitType->apartment_id !== $apartment->id, 404);

        $unitType->load('images');

        return view('admin.unit-types.form', compact('apartment', 'unitType'));
    }

    public function update(Request $request, UnitType $unitType): RedirectResponse
    {
        $apartment = $this->currentApartment($request);
        abort_if($unitType->apartment_id !== $apartment->id, 404);

        $unitType->update($this->validated($request, $unitType));
        $this->syncImages($unitType, $request);

        return redirect()->route('admin.unit-types.index')->with('status', "Unit type \"{$unitType->name}\" updated.");
    }

    public function destroy(Request $request, UnitType $unitType): RedirectResponse
    {
        $apartment = $this->currentApartment($request);
        abort_if($unitType->apartment_id !== $apartment->id, 404);

        $openBookings = $unitType->bookings()
            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->whereDate('check_out', '>=', now($apartment->timezone)->toDateString())
            ->count();

        if ($openBookings > 0) {
            return back()->with('error', "\"{$unitType->name}\" still has {$openBookings} pending or confirmed booking(s). Cancel them first, or hide the unit type instead.");
        }

        $unitType->delete();

        return redirect()->route('admin.unit-types.index')->with('status', 'Unit type deleted.');
    }

    private function validated(Request $request, ?UnitType $unitType = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(UnitType::CATEGORIES)],
            'listing_type' => ['nullable', Rule::in(UnitType::LISTING_TYPES)],
            'description' => ['nullable', 'string'],
            'translations.en.name' => ['nullable', 'string'],
            'translations.en.description' => ['nullable', 'string'],
            'translations.ja.name' => ['nullable', 'string'],
            'translations.ja.description' => ['nullable', 'string'],
            'size_sqm' => ['nullable', 'integer', 'min:1'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:10'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:10'],
            'floor_range' => ['nullable', 'string', 'max:20'],
            'max_adults' => ['required', 'integer', 'min:1'],
            'max_children' => ['required', 'integer', 'min:0'],
            'bed_type_1' => ['nullable', 'string', 'max:50'],
            'bed_count_1' => ['nullable', 'integer', 'min:1'],
            'bed_type_2' => ['nullable', 'string', 'max:50'],
            'bed_count_2' => ['nullable', 'integer', 'min:1'],
            'view_type' => ['nullable', 'string', 'max:50'],
            'breakfast_included' => ['sometimes', 'boolean'],
            'extra_bed_available' => ['sometimes', 'boolean'],
            'extra_bed_price' => ['nullable', 'numeric', 'min:0'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
            'yearly_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'amenities' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['category'] ??= $unitType?->category ?? UnitType::CATEGORY_RESIDENTIAL;
        $data['listing_type'] ??= $unitType?->listing_type ?? UnitType::LISTING_RENT;

        $this->assertPricedForListing($data);

        $bedConfig = [];
        if (! empty($data['bed_type_1'])) {
            $bedConfig[] = ['type' => $data['bed_type_1'], 'count' => (int) ($data['bed_count_1'] ?? 1)];
        }
        if (! empty($data['bed_type_2'])) {
            $bedConfig[] = ['type' => $data['bed_type_2'], 'count' => (int) ($data['bed_count_2'] ?? 1)];
        }

        $amenities = collect(explode(',', (string) ($data['amenities'] ?? '')))
            ->map(fn ($a) => Str::of($a)->trim()->slug('_')->toString())
            ->filter()
            ->values()
            ->all();

        return [
            'name' => $data['name'],
            'category' => $data['category'],
            'listing_type' => $data['listing_type'],
            'slug' => $unitType?->slug ?? $this->uniqueSlug($request->user()->currentApartment(), $data['name']),
            'description' => $data['description'] ?? null,
            'translations' => [
                'en' => ['name' => $data['translations']['en']['name'] ?? null, 'description' => $data['translations']['en']['description'] ?? null],
                'ja' => ['name' => $data['translations']['ja']['name'] ?? null, 'description' => $data['translations']['ja']['description'] ?? null],
            ],
            'size_sqm' => $data['size_sqm'] ?? null,
            'bedrooms' => $data['bedrooms'] ?? 0,
            'bathrooms' => $data['bathrooms'] ?? 0,
            'floor_range' => $data['floor_range'] ?? null,
            'max_adults' => $data['max_adults'],
            'max_children' => $data['max_children'],
            'bed_config' => $bedConfig,
            'view_type' => $data['view_type'] ?? null,
            'breakfast_included' => $request->boolean('breakfast_included'),
            'extra_bed_available' => $request->boolean('extra_bed_available'),
            'extra_bed_price' => $data['extra_bed_price'] ?? null,
            'base_price' => $data['base_price'] ?? 0,
            'monthly_price' => filled($data['monthly_price'] ?? null) ? $data['monthly_price'] : null,
            'yearly_price' => filled($data['yearly_price'] ?? null) ? $data['yearly_price'] : null,
            'sale_price' => filled($data['sale_price'] ?? null) ? $data['sale_price'] : null,
            'amenities' => $amenities,
            'is_active' => $request->boolean('is_active'),
            'sort_order' => $data['sort_order'] ?? 0,
        ];
    }

    /**
     * A unit for rent needs at least one rental rate and a unit for sale needs
     * an asking price, otherwise guests would see a listing with nothing to pay.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertPricedForListing(array $data): void
    {
        $listsForRent = in_array($data['listing_type'], [UnitType::LISTING_RENT, UnitType::LISTING_BOTH], true);
        $listsForSale = in_array($data['listing_type'], [UnitType::LISTING_SALE, UnitType::LISTING_BOTH], true);
        $hasRentalRate = collect(['base_price', 'monthly_price', 'yearly_price'])->contains(fn (string $field) => (float) ($data[$field] ?? 0) > 0);

        if ($listsForRent && ! $hasRentalRate) {
            throw ValidationException::withMessages(['base_price' => 'Set at least one rental rate (per night, per month or per year), or choose "For sale" only.']);
        }

        if ($listsForSale && (float) ($data['sale_price'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['sale_price' => 'Set the sale price for a unit that is for sale.']);
        }
    }

    private function uniqueSlug($apartment, string $name): string
    {
        $base = Str::slug($name) ?: 'unit';
        $slug = $base;
        $suffix = 1;

        while ($apartment->unitTypes()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function syncImages(UnitType $unitType, Request $request): void
    {
        $deleteIds = collect($request->input('delete_images', []))->map(fn ($id) => (int) $id);
        if ($deleteIds->isNotEmpty()) {
            $unitType->images()->whereIn('id', $deleteIds)->delete();
        }

        $newImages = collect($request->input('new_images', []))
            ->filter(fn ($row) => filled($row['url'] ?? null));

        $sort = $unitType->images()->max('sort_order') + 1;
        foreach ($newImages as $row) {
            $unitType->images()->create([
                'image_url' => $row['url'],
                'alt_text' => $row['alt'] ?? $unitType->name,
                'tags' => collect(explode(',', $row['tags'] ?? ''))->map(fn ($t) => trim($t))->filter()->values()->all(),
                'sort_order' => $sort++,
            ]);
        }
    }
}
