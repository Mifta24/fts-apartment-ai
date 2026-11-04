<x-admin-layout title="Units">
    <x-slot name="actions">
        <a href="{{ route('admin.unit-types.create') }}" class="rounded-lg bg-ink px-3 py-2 text-sm font-medium text-white hover:bg-signal hover:text-signal-ink">
            Add unit type
        </a>
    </x-slot>

    <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase text-stone-500">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Layout</th>
                    <th class="px-4 py-3">Price</th>
                    <th class="px-4 py-3">Occupancy</th>
                    <th class="px-4 py-3">Images</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($unitTypes as $unitType)
                    <tr>
                        <td class="px-4 py-3 font-medium text-stone-900">
                            {{ $unitType->name }}
                            <span class="block text-xs font-normal text-stone-400">{{ ucfirst($unitType->category) }} · {{ ['rent' => 'For rent', 'sale' => 'For sale', 'both' => 'Rent & sale'][$unitType->listing_type] ?? $unitType->listing_type }}</span>
                        </td>
                        <td class="px-4 py-3">@if ($unitType->isResidential()){{ $unitType->isStudio() ? 'Studio' : $unitType->bedrooms.' BR' }} · @endif{{ $unitType->bathrooms }} BA @if($unitType->floor_range)<span class="text-stone-400">· fl. {{ $unitType->floor_range }}</span>@endif</td>
                        <td class="px-4 py-3">
                            @if ($unitType->offersMonthlyRental()){{ $apartment->currency }} {{ number_format((float) $unitType->monthly_price, 0, ',', '.') }} / month<br>@endif
                            @if ($unitType->offersYearlyRental())<span class="text-xs text-stone-500">{{ number_format((float) $unitType->yearly_price, 0, ',', '.') }} / year</span><br>@endif
                            @if ($unitType->offersSalePrice())<span class="text-xs text-stone-500">Sale {{ number_format((float) $unitType->sale_price, 0, ',', '.') }}</span>@endif
                        </td>
                        <td class="px-4 py-3">{{ $unitType->max_adults }}A / {{ $unitType->max_children }}C</td>
                        <td class="px-4 py-3">{{ $unitType->images_count }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $unitType->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-100 text-stone-500' }}">
                                {{ $unitType->is_active ? 'Active' : 'Hidden' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($unitType->isForRent())
                                <a href="{{ route('admin.unit-types.inventory.index', $unitType) }}" class="text-stone-600 hover:underline">Inventory</a>
                            @endif
                            <a href="{{ route('admin.unit-types.edit', $unitType) }}" class="ml-3 text-stone-600 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('admin.unit-types.destroy', $unitType) }}" class="inline" onsubmit="return confirm('Delete this unit type?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="ml-3 text-red-600 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">No unit types yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin-layout>
