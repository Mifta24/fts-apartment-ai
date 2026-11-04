@php
    $actionStyles = [
        'approved' => ['Approve', 'text-emerald-600'],
        'deposit_paid' => ['Deposit received', 'text-emerald-600'],
        'active' => ['Start lease', 'text-emerald-600'],
        'completed' => ['Complete lease', 'text-stone-600'],
        'rejected' => ['Reject', 'text-red-600'],
        'cancelled' => ['Cancel', 'text-red-600'],
    ];
@endphp
@foreach ($booking->nextStatuses() as $next)
    @php [$actionLabel, $actionColor] = $actionStyles[$next]; @endphp
    <form method="POST" action="{{ route('admin.bookings.status', $booking) }}" class="inline [&:not(:first-child)]:ml-3" @if (in_array($next, ['rejected', 'cancelled'], true)) onsubmit="return confirm('{{ $actionLabel }} this application?')" @endif>
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="{{ $next }}">
        <button type="submit" class="{{ $button ?? '' }} {{ $actionColor }} hover:underline">{{ $actionLabel }}</button>
    </form>
@endforeach
