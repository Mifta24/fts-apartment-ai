@php
    $isOwner = auth()->user()?->currentApartment()?->pivot?->role === 'owner';

    $navItems = [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard'],
        ['route' => 'admin.unit-types.index', 'label' => 'Units'],
        ['route' => 'admin.knowledge-items.index', 'label' => 'Knowledge base'],
        ['route' => 'admin.bookings.index', 'label' => 'Rental applications'],
        ['route' => 'admin.viewings.index', 'label' => 'Viewings'],
        ['route' => 'admin.handovers.index', 'label' => 'Handovers'],
    ];

    if ($isOwner) {
        $navItems[] = ['route' => 'admin.settings.edit', 'label' => 'Settings'];
    }
@endphp
<nav class="space-y-1 px-2 py-4 text-sm" aria-label="Admin">
    @foreach ($navItems as $item)
        <a
            href="{{ route($item['route']) }}"
            @if (request()->routeIs($item['route'].'*')) aria-current="page" @endif
            class="block rounded-lg px-3 py-2.5 {{ request()->routeIs($item['route'].'*') ? 'bg-signal font-medium text-signal-ink' : 'text-white/70 hover:bg-white/10 hover:text-white' }}"
        >{{ $item['label'] }}</a>
    @endforeach
</nav>
<form method="POST" action="{{ route('admin.logout') }}" class="border-t border-white/10 p-2">
    @csrf
    <button type="submit" class="w-full rounded-lg px-3 py-2.5 text-left text-sm text-white/60 hover:bg-white/10 hover:text-white">Log out</button>
</form>
