@props(['apartment', 'locale', 'supportedLocales', 'labels', 'lobby', 'narration', 'scene', 'floor', 'backdrop', 'menuItems', 'title' => null, 'welcome' => null, 'sidebar' => null])
{{-- The fixed full-screen stage every floor shares: photo, lift doors, floor display, lift panel and the concierge chat dock. --}}
@php
    $lobbyUrl = route('apartment.show', ['apartmentSlug' => $apartment->slug, 'lang' => $locale]);
    $currentKey = ['unit' => 'units', 'facility' => 'facilities'][$scene] ?? $scene;
    $currentLabel = collect($menuItems)->firstWhere('key', $currentKey)['label'] ?? $lobby['home'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? $apartment->name.' · AI Concierge' }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="stage-page antialiased" style="--concierge-image: url('{{ $backdrop['avatarImage'] }}'); --stage-focus: {{ $backdrop['focus'] }}; --stage-focus-mobile: {{ $backdrop['focusMobile'] }}; --concierge-zoom: {{ $backdrop['avatarZoom'] }}; --concierge-focus: {{ $backdrop['avatarFocus'] }}">
    <main class="stage" data-lobby data-scene="{{ $scene }}" data-page="{{ $scene === 'lobby' ? 'home' : $scene }}" data-floor="{{ $floor }}" data-chat-dock="right">
        <div class="stage-loader" data-stage-loader role="status">
            <span class="lift-door lift-door-left" aria-hidden="true"></span>
            <span class="lift-door lift-door-right" aria-hidden="true"></span>
            <span class="stage-loader-floor" aria-hidden="true">{{ $floor }}</span>
            <p>{{ $lobby['loading'] }}</p>
        </div>
        <img src="{{ $backdrop['image'] }}" alt="" class="stage-image" fetchpriority="high">
        <div class="stage-shade"></div>
        @if ($scene !== 'unit' && $backdrop['character'])
            <img src="{{ $backdrop['character'] }}" alt="{{ $lobby['assistant'] }}" class="stage-character" data-pose="{{ $backdrop['pose'] }}">
        @endif
        <p class="stage-tour" data-stage-tour aria-live="polite"></p>

        <header class="stage-header">
            <a href="{{ $lobbyUrl }}" @if($scene !== 'lobby') data-stage-exit data-tour-line="{{ $narration['tour_lobby'] }}" @endif class="stage-brand">
                <x-apartment-mark />
                <span class="min-w-0"><span class="stage-brand-name">{{ $apartment->name }}</span><span class="stage-brand-city">{{ $apartment->city }} · {{ $apartment->country }}</span></span>
            </a>
            <div class="floor-display" aria-live="polite">
                <span class="floor-display-arrow" data-floor-arrow aria-hidden="true">▲</span>
                <span class="floor-display-code" data-floor-code>{{ $floor }}</span>
                <span class="floor-display-label">{{ $currentLabel }}</span>
            </div>
            <div class="stage-tools">
                <x-sound-toggle :on="$lobby['sound_on']" :off="$lobby['sound_off']" />
                <nav aria-label="Language" class="stage-lang">
                    @foreach ($supportedLocales as $code)
                        <a href="?lang={{ $code }}" lang="{{ $code }}" aria-label="{{ ['id' => 'Bahasa Indonesia', 'en' => 'English', 'ja' => '日本語'][$code] }}" @if($code === $locale) aria-current="true" @endif>{{ $code }}</a>
                    @endforeach
                </nav>
            </div>
        </header>

        {{-- The lift panel: top floor first, lobby at the bottom, like the buttons inside the car. --}}
        <nav class="lift-panel" aria-label="{{ $lobby['explore'] }}">
            <p class="lift-panel-title">{{ $lobby['explore'] }}</p>
            <ol class="lift-buttons">
                @foreach (array_reverse($menuItems) as $item)
                    <li style="--strip-order: {{ $loop->remaining + 1 }}">
                        <a href="{{ $item['href'] }}" class="lift-button"
                            data-stage-exit @if($item['tour']) data-tour-line="{{ $item['tour'] }}" @endif data-topic="{{ $item['topic'] }}"
                            @if($item['key'] === $currentKey) aria-current="page" @endif>
                            <span class="lift-key" aria-hidden="true">{{ $item['floor'] }}</span>
                            <span class="lift-label">{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
                <li style="--strip-order: 0">
                    <a href="{{ $lobbyUrl }}" class="lift-button" @if($scene !== 'lobby') data-stage-exit data-tour-line="{{ $narration['tour_lobby'] }}" @else aria-current="page" @endif>
                        <span class="lift-key" aria-hidden="true">L</span>
                        <span class="lift-label">{{ $lobby['home'] }}</span>
                    </a>
                </li>
            </ol>
            <span class="lift-panel-footer"><span class="status-dot" aria-hidden="true"></span>{{ $lobby['available'] }}</span>
        </nav>

        {{ $welcome }}

        {{ $sidebar }}

        {{ $slot }}

        <div data-chat-widget>
            @include('apartment.chat')
        </div>
        <noscript><p class="stage-noscript">Aktifkan JavaScript untuk menggunakan navigasi dan AI Concierge. {{ $apartment->phone }}</p></noscript>
    </main>
</body>
</html>
