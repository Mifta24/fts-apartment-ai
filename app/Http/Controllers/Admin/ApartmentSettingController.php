<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesCurrentApartment;
use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\ApartmentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Contact details, lease rules and whether the building
 * is visible to guests. Only the owner may change them.
 */
class ApartmentSettingController extends Controller
{
    use ResolvesCurrentApartment;

    public function edit(Request $request): View
    {
        return view('admin.settings.edit', ['apartment' => $this->ownedApartment($request)]);
    }

    public function update(Request $request): RedirectResponse
    {
        $apartment = $this->ownedApartment($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'translations.en.description' => ['nullable', 'string', 'max:2000'],
            'translations.ja.description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'default_locale' => ['required', Rule::in(['id', 'en', 'ja'])],
            'security_deposit_months' => ['sometimes', 'integer', 'min:0', 'max:12'],
            'min_monthly_lease_months' => ['sometimes', 'integer', 'min:1', 'max:'.Apartment::MAX_MONTHLY_LEASE_MONTHS],
            'public_status' => ['required', Rule::in(['draft', 'published'])],
        ]);

        $apartment->update([
            ...$data,
            'translations' => [
                ...($apartment->translations ?? []),
                'en' => [...($apartment->translations['en'] ?? []), 'description' => $data['translations']['en']['description'] ?? null],
                'ja' => [...($apartment->translations['ja'] ?? []), 'description' => $data['translations']['ja']['description'] ?? null],
            ],
        ]);

        return redirect()->route('admin.settings.edit')->with('status', 'Apartment settings saved.');
    }

    private function ownedApartment(Request $request): Apartment
    {
        $apartment = $this->currentApartment($request);

        abort_unless($apartment->pivot?->role === ApartmentUser::ROLE_OWNER, 403, 'Only the owner can change apartment settings.');

        return $apartment;
    }
}
