<x-admin-layout title="Bookings">
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled'] as $value => $label)
            <a href="{{ route('admin.bookings.index', $value ? ['status' => $value] : []) }}"
                class="rounded-full px-3 py-1 {{ $status === $value || (! $status && $value === '') ? 'bg-ink text-white' : 'bg-white border border-stone-300 text-stone-600' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="hidden overflow-x-auto rounded-xl border border-stone-200 bg-white md:block">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase text-stone-500">
                <tr>
                    <th class="px-4 py-3">Reference</th>
                    <th class="px-4 py-3">Guest</th>
                    <th class="px-4 py-3">Unit</th>
                    <th class="px-4 py-3">Dates</th>
                    <th class="px-4 py-3">Total</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($bookings as $booking)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $booking->reference }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium text-stone-900">{{ $booking->guest_name }}</p>
                            <p class="text-xs text-stone-500">{{ $booking->contact_type ? ucfirst($booking->contact_type).': ' : '' }}{{ $booking->guest_phone ?? $booking->guest_email }}</p>
                            @if ($booking->notes)<p class="mt-1 max-w-xs text-xs italic text-stone-400">{{ $booking->notes }}</p>@endif
                        </td>
                        <td class="px-4 py-3">{{ $booking->unit_count }} × {{ $booking->unitType->name }}<p class="text-xs text-stone-400">{{ $booking->adults }} adults{{ $booking->children ? ', '.$booking->children.' children' : '' }}</p></td>
                        <td class="px-4 py-3 text-xs text-stone-500">
                            @if ($booking->isLease())<p class="font-medium text-stone-700">{{ $booking->rental_type === 'yearly' ? 'Yearly' : 'Monthly' }} · {{ $booking->lease_months }} {{ $booking->lease_months === 1 ? 'month' : 'months' }}</p>@endif
                            {{ $booking->check_in->toFormattedDateString() }} → {{ $booking->check_out->toFormattedDateString() }}
                        </td>
                        <td class="px-4 py-3">{{ $apartment->currency }} {{ number_format((float) $booking->total_price, 0, ',', '.') }}@if ($booking->deposit_total)<p class="text-xs text-stone-400">+ {{ number_format((float) $booking->deposit_total, 0, ',', '.') }} deposit</p>@endif</td>
                        <td class="px-4 py-3">@include('admin.bookings._status')</td>
                        <td class="px-4 py-3 text-right">
                            @include('admin.bookings._actions')
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">No bookings found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="space-y-3 md:hidden">
        @forelse ($bookings as $booking)
            <article class="rounded-xl border border-stone-200 bg-white p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-mono text-xs text-stone-500">{{ $booking->reference }}</p>
                        <p class="mt-0.5 truncate font-medium text-stone-900">{{ $booking->guest_name }}</p>
                    </div>
                    @include('admin.bookings._status')
                </div>
                <p class="mt-2 text-sm text-stone-700">{{ $booking->unit_count }} × {{ $booking->unitType->name }} · {{ $booking->adults }} adults{{ $booking->children ? ', '.$booking->children.' children' : '' }}</p>
                <p class="mt-1 text-xs text-stone-500">{{ $booking->check_in->toFormattedDateString() }} → {{ $booking->check_out->toFormattedDateString() }} · {{ $booking->isLease() ? $booking->lease_months.' '.($booking->lease_months === 1 ? 'month' : 'months').' ('.($booking->rental_type === 'yearly' ? 'yearly' : 'monthly').')' : $booking->nights().' nights' }}</p>
                <p class="mt-1 text-sm font-medium text-stone-900">{{ $apartment->currency }} {{ number_format((float) $booking->total_price, 0, ',', '.') }}@if ($booking->deposit_total) <span class="text-xs font-normal text-stone-400">+ {{ number_format((float) $booking->deposit_total, 0, ',', '.') }} deposit</span>@endif</p>
                <p class="mt-2 text-xs text-stone-500">{{ $booking->contact_type ? ucfirst($booking->contact_type).': ' : '' }}{{ $booking->guest_phone ?? $booking->guest_email }}</p>
                @if ($booking->notes)<p class="mt-1 text-xs italic text-stone-400">{{ $booking->notes }}</p>@endif
                @if ($booking->canTransitionTo('confirmed') || $booking->canTransitionTo('cancelled'))
                    <div class="mt-3 flex items-center gap-1 border-t border-stone-100 pt-3 text-sm">
                        @include('admin.bookings._actions', ['button' => 'min-h-11 px-1 font-medium'])
                    </div>
                @endif
            </article>
        @empty
            <p class="rounded-xl border border-stone-200 bg-white px-4 py-8 text-center text-stone-400">No bookings found.</p>
        @endforelse
    </div>

    <div class="mt-4">{{ $bookings->links() }}</div>
</x-admin-layout>
