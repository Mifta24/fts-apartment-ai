@php
    $field = 'mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm';
@endphp

<x-admin-layout title="Apartment settings">
    <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-8">
        @csrf @method('PUT')

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Building</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label for="name" class="block text-sm font-medium text-stone-700">Name</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $apartment->name) }}" required class="{{ $field }}">
                </div>
                <div class="col-span-2">
                    <label for="description" class="block text-sm font-medium text-stone-700">Description (Indonesian, default)</label>
                    <textarea id="description" name="description" rows="2" class="{{ $field }}">{{ old('description', $apartment->description) }}</textarea>
                </div>
                <div>
                    <label for="description_en" class="block text-sm font-medium text-stone-700">Description (English)</label>
                    <textarea id="description_en" name="translations[en][description]" rows="2" class="{{ $field }}">{{ old('translations.en.description', $apartment->translations['en']['description'] ?? '') }}</textarea>
                </div>
                <div>
                    <label for="description_ja" class="block text-sm font-medium text-stone-700">Description (Japanese)</label>
                    <textarea id="description_ja" name="translations[ja][description]" rows="2" class="{{ $field }}">{{ old('translations.ja.description', $apartment->translations['ja']['description'] ?? '') }}</textarea>
                </div>
                <div class="col-span-2">
                    <label for="address" class="block text-sm font-medium text-stone-700">Address</label>
                    <input id="address" type="text" name="address" value="{{ old('address', $apartment->address) }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="city" class="block text-sm font-medium text-stone-700">City</label>
                    <input id="city" type="text" name="city" value="{{ old('city', $apartment->city) }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="country" class="block text-sm font-medium text-stone-700">Country</label>
                    <input id="country" type="text" name="country" value="{{ old('country', $apartment->country) }}" class="{{ $field }}">
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Contact</h2>
            <p class="mt-1 text-xs text-stone-500">Guests reach the team through these. They also get the stay summary by WhatsApp, phone or email.</p>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div>
                    <label for="phone" class="block text-sm font-medium text-stone-700">Phone</label>
                    <input id="phone" type="text" name="phone" value="{{ old('phone', $apartment->phone) }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="whatsapp" class="block text-sm font-medium text-stone-700">WhatsApp</label>
                    <input id="whatsapp" type="text" name="whatsapp" value="{{ old('whatsapp', $apartment->whatsapp) }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $apartment->email) }}" class="{{ $field }}">
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Language and time zone</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label for="default_locale" class="block text-sm font-medium text-stone-700">Default language</label>
                    <select id="default_locale" name="default_locale" class="{{ $field }}">
                        @foreach (['id' => 'Indonesian', 'en' => 'English', 'ja' => 'Japanese'] as $code => $label)
                            <option value="{{ $code }}" @selected(old('default_locale', $apartment->default_locale) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="timezone" class="block text-sm font-medium text-stone-700">Time zone</label>
                    <input id="timezone" type="text" name="timezone" value="{{ old('timezone', $apartment->timezone) }}" required list="timezones" class="{{ $field }}">
                    <datalist id="timezones">
                        @foreach (['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura', 'Asia/Tokyo', 'Asia/Singapore'] as $zone)
                            <option value="{{ $zone }}">
                        @endforeach
                    </datalist>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Leases</h2>
            <p class="mt-1 text-xs text-stone-500">The rent itself is set on each unit type. Monthly leases run up to {{ \App\Models\Apartment::MAX_MONTHLY_LEASE_MONTHS }} months; a year or more is a yearly rental, up to {{ \App\Models\Apartment::MAX_YEARLY_LEASE_YEARS }} years. The deposit is refundable and is based on one month of rent.</p>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label for="security_deposit_months" class="block text-sm font-medium text-stone-700">Security deposit (months of rent)</label>
                    <input id="security_deposit_months" type="number" name="security_deposit_months" value="{{ old('security_deposit_months', $apartment->security_deposit_months) }}" min="0" max="12" required class="{{ $field }}">
                </div>
                <div>
                    <label for="min_monthly_lease_months" class="block text-sm font-medium text-stone-700">Shortest monthly lease (months)</label>
                    <input id="min_monthly_lease_months" type="number" name="min_monthly_lease_months" value="{{ old('min_monthly_lease_months', $apartment->min_monthly_lease_months) }}" min="1" max="{{ \App\Models\Apartment::MAX_MONTHLY_LEASE_MONTHS }}" required class="{{ $field }}">
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Visibility</h2>
            <div class="mt-4">
                <label for="public_status" class="block text-sm font-medium text-stone-700">Public page</label>
                <select id="public_status" name="public_status" class="{{ $field }} sm:w-60">
                    <option value="published" @selected(old('public_status', $apartment->public_status) === 'published')>Published — guests can see it</option>
                    <option value="draft" @selected(old('public_status', $apartment->public_status) === 'draft')>Draft — hidden from guests</option>
                </select>
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="rounded-lg bg-ink px-4 py-2 text-sm font-medium text-white hover:bg-signal hover:text-signal-ink">Save settings</button>
        </div>
    </form>
</x-admin-layout>
