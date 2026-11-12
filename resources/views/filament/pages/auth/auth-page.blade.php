{{-- A sign-in page in the auth layout: an optional "back" link, the heading, the page's content and an optional closing line. --}}
@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    $heading = $this->getHeading();
    $subheading = $this->getSubheading();
@endphp

<div class="contiu-auth-page">
    @if ($backLink)
        <a href="{{ $backLink['url'] }}" class="contiu-auth-back">
            <x-filament::icon :icon="Phosphor::CaretLeft" />{{ $backLink['label'] }}
        </a>
    @endif

    <header class="contiu-auth-head">
        <h1 class="contiu-auth-title">{{ $heading }}</h1>

        @if (filled($subheading))
            <p class="contiu-auth-lede">{{ $subheading }}</p>
        @endif
    </header>

    {{ $this->content }}

    @if (filled($closingLine))
        <p class="contiu-auth-closing">{{ $closingLine }}</p>
    @endif

    <x-filament-actions::modals />
</div>
