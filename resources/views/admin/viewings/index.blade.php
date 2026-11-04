<x-admin-layout title="Viewings">
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['' => 'All', 'requested' => 'Requested', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $value => $label)
            <a href="{{ route('admin.viewings.index', $value ? ['status' => $value] : []) }}"
                class="rounded-full px-3 py-1 {{ $status === $value || (! $status && $value === '') ? 'bg-ink text-white' : 'bg-white border border-stone-300 text-stone-600' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($viewings as $viewing)
            <article class="rounded-xl border border-stone-200 bg-white p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-mono text-xs text-stone-500">{{ $viewing->reference }}</p>
                        <p class="mt-0.5 truncate font-medium text-stone-900">{{ $viewing->visitor_name }}</p>
                    </div>
                    <span @class([
                        'rounded-full px-2 py-0.5 text-xs',
                        'bg-amber-100 text-amber-700' => $viewing->status === 'requested',
                        'bg-sky-100 text-sky-700' => $viewing->status === 'scheduled',
                        'bg-emerald-100 text-emerald-700' => $viewing->status === 'completed',
                        'bg-stone-100 text-stone-500' => $viewing->status === 'cancelled',
                    ])>{{ ucfirst($viewing->status) }}</span>
                </div>
                <p class="mt-2 text-sm text-stone-700">{{ $viewing->unitType->name }} · {{ $viewing->preferred_date->toFormattedDateString() }}, {{ $viewing->preferred_time }}</p>
                <p class="mt-1 text-xs text-stone-500">{{ $viewing->contact_type ? ucfirst($viewing->contact_type).': ' : '' }}{{ $viewing->visitor_phone ?? $viewing->visitor_email }}</p>
                @if ($viewing->notes)<p class="mt-1 text-xs italic text-stone-400">{{ $viewing->notes }}</p>@endif
                @php
                    $viewingActions = ['scheduled' => ['Mark scheduled', 'text-emerald-600'], 'completed' => ['Mark completed', 'text-stone-600'], 'cancelled' => ['Cancel', 'text-red-600']];
                @endphp
                @if ($viewing->canTransitionTo('scheduled') || $viewing->canTransitionTo('completed') || $viewing->canTransitionTo('cancelled'))
                    <div class="mt-3 flex items-center gap-4 border-t border-stone-100 pt-3 text-sm">
                        @foreach ($viewingActions as $next => [$actionLabel, $actionColor])
                            @if ($viewing->canTransitionTo($next))
                                <form method="POST" action="{{ route('admin.viewings.status', $viewing) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $next }}">
                                    <button type="submit" class="min-h-11 font-medium {{ $actionColor }} hover:underline">{{ $actionLabel }}</button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <p class="rounded-xl border border-stone-200 bg-white px-4 py-8 text-center text-stone-400">No viewing requests found.</p>
        @endforelse
    </div>

    <div class="mt-4">{{ $viewings->links() }}</div>
</x-admin-layout>
