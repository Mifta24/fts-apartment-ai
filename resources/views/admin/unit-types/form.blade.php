@php
    $isEdit = $unitType->exists;
    $bed1 = $unitType->bed_config[0] ?? null;
    $bed2 = $unitType->bed_config[1] ?? null;
@endphp

<x-admin-layout :title="$isEdit ? 'Edit unit type' : 'Add unit type'">
    <form method="POST" action="{{ $isEdit ? route('admin.unit-types.update', $unitType) : route('admin.unit-types.store') }}" class="space-y-8">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Basics</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700">Category</label>
                    <select name="category" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                        @foreach (['residential' => 'Residential (home)', 'office' => 'Office', 'commercial' => 'Commercial / retail'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('category', $unitType->category) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Listed as</label>
                    <select name="listing_type" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                        @foreach (['rent' => 'For rent', 'sale' => 'For sale', 'both' => 'For rent and for sale'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('listing_type', $unitType->listing_type) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-stone-700">Name (Indonesian, default)</label>
                    <input type="text" name="name" value="{{ old('name', $unitType->name) }}" required
                        class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-stone-700">Description (Indonesian, default)</label>
                    <textarea name="description" rows="2" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('description', $unitType->description) }}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Name (English)</label>
                    <input type="text" name="translations[en][name]" value="{{ old('translations.en.name', $unitType->translations['en']['name'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Name (Japanese)</label>
                    <input type="text" name="translations[ja][name]" value="{{ old('translations.ja.name', $unitType->translations['ja']['name'] ?? '') }}"
                        class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Description (English)</label>
                    <textarea name="translations[en][description]" rows="2" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('translations.en.description', $unitType->translations['en']['description'] ?? '') }}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Description (Japanese)</label>
                    <textarea name="translations[ja][description]" rows="2" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('translations.ja.description', $unitType->translations['ja']['description'] ?? '') }}</textarea>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Layout & occupancy</h2>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700">Size (m²)</label>
                    <input type="number" name="size_sqm" value="{{ old('size_sqm', $unitType->size_sqm) }}" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Bedrooms (0 = studio)</label>
                    <input type="number" name="bedrooms" value="{{ old('bedrooms', $unitType->bedrooms ?? 1) }}" min="0" max="10" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Bathrooms</label>
                    <input type="number" name="bathrooms" value="{{ old('bathrooms', $unitType->bathrooms ?? 1) }}" min="0" max="10" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Floors</label>
                    <input type="text" name="floor_range" value="{{ old('floor_range', $unitType->floor_range) }}" placeholder="10–20" maxlength="20" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Max adults</label>
                    <input type="number" name="max_adults" value="{{ old('max_adults', $unitType->max_adults) }}" required min="1" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Max children</label>
                    <input type="number" name="max_children" value="{{ old('max_children', $unitType->max_children) }}" required min="0" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">View type</label>
                    <input type="text" name="view_type" value="{{ old('view_type', $unitType->view_type) }}" placeholder="city, skyline, park…" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Bed 1 type</label>
                    <input type="text" name="bed_type_1" value="{{ old('bed_type_1', $bed1['type'] ?? '') }}" placeholder="king" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Bed 1 count</label>
                    <input type="number" name="bed_count_1" value="{{ old('bed_count_1', $bed1['count'] ?? '') }}" min="1" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Bed 2 type</label>
                    <input type="text" name="bed_type_2" value="{{ old('bed_type_2', $bed2['type'] ?? '') }}" placeholder="twin (optional)" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Bed 2 count</label>
                    <input type="number" name="bed_count_2" value="{{ old('bed_count_2', $bed2['count'] ?? '') }}" min="1" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Rent & listing</h2>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700">Monthly rent / month</label>
                    <input type="number" step="1000" min="0" name="monthly_price" value="{{ old('monthly_price', $unitType->monthly_price) }}" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Yearly rent / year</label>
                    <input type="number" step="1000" min="0" name="yearly_price" value="{{ old('yearly_price', $unitType->yearly_price) }}" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Sale price</label>
                    <input type="number" step="1000" min="0" name="sale_price" value="{{ old('sale_price', $unitType->sale_price) }}" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Sort order</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $unitType->sort_order) }}" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
            </div>
            <p class="mt-3 text-xs text-stone-500">The sale price is only used for units listed for sale; sale enquiries go to the team, not the rental application. Leave the monthly or yearly rent empty if this unit is not rented that way. The yearly rent is the price for one full year; two or three years are charged as a multiple of it.</p>
            <div class="mt-4 flex flex-wrap gap-6">
                <label class="flex items-center gap-2 text-sm text-stone-700">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $unitType->is_active)) class="rounded border-stone-300">
                    Published (visible on the website)
                </label>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium text-stone-700">Amenities (comma-separated)</label>
                <input type="text" name="amenities" value="{{ old('amenities', implode(', ', $unitType->amenities ?? [])) }}" placeholder="wifi, full_kitchen, washer_dryer, workspace, balcony"
                    class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Photos</h2>

            @if ($isEdit && $unitType->images->isNotEmpty())
                <div class="mt-4 space-y-2">
                    @foreach ($unitType->images as $image)
                        <div class="flex items-center gap-3 rounded-lg border border-stone-200 p-2">
                            <img src="{{ $image->image_source }}" alt="{{ $image->alt_text }}" class="h-14 w-20 rounded object-cover">
                            <div class="flex-1 text-xs text-stone-500">
                                <p class="truncate">{{ $image->image_url }}</p>
                                <p>Tags: {{ implode(', ', $image->tags ?? []) }}</p>
                            </div>
                            <label class="flex items-center gap-1 text-xs text-red-600">
                                <input type="checkbox" name="delete_images[]" value="{{ $image->id }}" class="rounded border-stone-300">
                                Delete
                            </label>
                        </div>
                    @endforeach
                </div>
            @endif

            <p class="mt-4 text-xs text-stone-500">Add photos by URL (e.g. an Unsplash or CDN link). Tag them so the AI Concierge can pick the right one — e.g. "bathroom, bathtub" or "view, balcony".</p>
            @for ($i = 0; $i < 3; $i++)
                <div class="mt-2 grid grid-cols-6 gap-2">
                    <input type="url" name="new_images[{{ $i }}][url]" placeholder="https://…" class="col-span-3 rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    <input type="text" name="new_images[{{ $i }}][tags]" placeholder="bedroom, view" class="col-span-2 rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    <input type="text" name="new_images[{{ $i }}][alt]" placeholder="Alt text" class="rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
            @endfor
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.unit-types.index') }}" class="rounded-lg border border-stone-300 px-4 py-2 text-sm text-stone-600 hover:bg-stone-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-ink px-4 py-2 text-sm font-medium text-white hover:bg-signal hover:text-signal-ink">Save unit type</button>
        </div>
    </form>
</x-admin-layout>
