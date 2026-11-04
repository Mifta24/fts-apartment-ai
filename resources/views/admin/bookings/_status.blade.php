<span @class([
    'rounded-full px-2 py-0.5 text-xs',
    'bg-amber-100 text-amber-700' => $booking->status === 'pending',
    'bg-sky-100 text-sky-700' => in_array($booking->status, ['approved', 'deposit_paid'], true),
    'bg-emerald-100 text-emerald-700' => $booking->status === 'active',
    'bg-stone-100 text-stone-500' => in_array($booking->status, ['completed', 'rejected', 'cancelled'], true),
])>{{ $booking->statusLabel() }}</span>
