<x-admin-layout title="Apartments">
    <p class="mb-4 text-sm text-stone-500">The numbered apartments visitors see on each unit page. Set a taken apartment to Occupied or Reserved with the date it becomes free and it is listed as "free from" that date; Maintenance hides it. The application engine still works per unit type.</p>

    @foreach ($unitTypes as $unitType)
        <section class="mb-6 rounded-xl border border-stone-200 bg-white">
            <h2 class="border-b border-stone-200 px-4 py-3 text-sm font-semibold text-stone-900">{{ $unitType->name }} <span class="font-normal text-stone-400">· {{ $unitType->units->count() }}</span></h2>
            <div class="divide-y divide-stone-100">
                @forelse ($unitType->units as $unit)
                    <form method="POST" action="{{ route('admin.units.update', $unit) }}" class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm">
                        @csrf @method('PUT')
                        <span class="w-16 font-mono font-medium text-stone-900">{{ $unit->unit_number }}</span>
                        <span class="w-20 text-stone-500">Floor {{ $unit->floor === 0 ? 'G' : $unit->floor }}</span>
                        <select name="facing" class="rounded-lg border border-stone-300 px-2 py-1.5 text-sm" aria-label="Facing">
                            <option value="">—</option>
                            @foreach (\App\Models\Unit::FACINGS as $facing)
                                <option value="{{ $facing }}" @selected($unit->facing === $facing)>{{ ucfirst($facing) }}</option>
                            @endforeach
                        </select>
                        <select name="status" class="rounded-lg border border-stone-300 px-2 py-1.5 text-sm" aria-label="Status">
                            @foreach (\App\Models\Unit::STATUSES as $status)
                                <option value="{{ $status }}" @selected($unit->status === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                        <input type="date" name="available_from" value="{{ $unit->available_from?->toDateString() }}" class="rounded-lg border border-stone-300 px-2 py-1.5 text-sm" aria-label="Free from">
                        <button type="submit" class="rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white hover:bg-signal hover:text-signal-ink">Save</button>
                        <button type="submit" form="delete-unit-{{ $unit->id }}" class="text-xs text-red-600 hover:underline">Remove</button>
                    </form>
                    <form id="delete-unit-{{ $unit->id }}" method="POST" action="{{ route('admin.units.destroy', $unit) }}" onsubmit="return confirm('Remove apartment {{ $unit->unit_number }}?')">@csrf @method('DELETE')</form>
                @empty
                    <p class="px-4 py-4 text-sm text-stone-400">No numbered apartments yet.</p>
                @endforelse
            </div>
        </section>
    @endforeach

    <section class="rounded-xl border border-stone-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-stone-900">Add an apartment</h2>
        <form method="POST" action="{{ route('admin.units.store') }}" class="mt-3 flex flex-wrap items-end gap-3 text-sm">
            @csrf
            <label class="block"><span class="block text-xs text-stone-500">Type</span>
                <select name="unit_type_id" required class="rounded-lg border border-stone-300 px-2 py-1.5">
                    @foreach ($unitTypes as $unitType)<option value="{{ $unitType->id }}">{{ $unitType->name }}</option>@endforeach
                </select>
            </label>
            <label class="block"><span class="block text-xs text-stone-500">Number</span><input type="text" name="unit_number" required maxlength="12" class="w-24 rounded-lg border border-stone-300 px-2 py-1.5"></label>
            <label class="block"><span class="block text-xs text-stone-500">Floor</span><input type="number" name="floor" min="0" max="200" required class="w-20 rounded-lg border border-stone-300 px-2 py-1.5"></label>
            <label class="block"><span class="block text-xs text-stone-500">Facing</span>
                <select name="facing" class="rounded-lg border border-stone-300 px-2 py-1.5"><option value="">—</option>@foreach (\App\Models\Unit::FACINGS as $facing)<option value="{{ $facing }}">{{ ucfirst($facing) }}</option>@endforeach</select>
            </label>
            <label class="block"><span class="block text-xs text-stone-500">Status</span>
                <select name="status" class="rounded-lg border border-stone-300 px-2 py-1.5">@foreach (\App\Models\Unit::STATUSES as $status)<option value="{{ $status }}">{{ ucfirst($status) }}</option>@endforeach</select>
            </label>
            <label class="block"><span class="block text-xs text-stone-500">Free from</span><input type="date" name="available_from" class="rounded-lg border border-stone-300 px-2 py-1.5"></label>
            <button type="submit" class="rounded-lg bg-ink px-3 py-2 text-sm font-medium text-white hover:bg-signal hover:text-signal-ink">Add</button>
        </form>
        @if ($errors->any())<p class="mt-3 text-sm text-red-600">{{ $errors->first() }}</p>@endif
    </section>
</x-admin-layout>
