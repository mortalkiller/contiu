{{--
    Perfil → Aparência (App\Livewire\AppearanceSettings). Each palette is a button with a small
    drawing of the panel in that palette's own colours; styles are in theme.css (.contiu-palette-*).
--}}
@php
    $thumbnail = fn (\App\Support\Appearance\Palette $palette): string => implode('; ', [
        '--thumb-navigation: '.$palette->navigation,
        '--thumb-navigation-text: '.\App\Support\Appearance\Palette::textOn($palette->navigation),
        '--thumb-background: '.$palette->background,
        '--thumb-action: '.$palette->action,
        '--thumb-primary: '.$palette->primary,
    ]);
@endphp

<div class="contiu-appearance">
    <x-filament::section>
        <x-slot name="heading">Modo</x-slot>
        <x-slot name="description">Claro, escuro ou igual ao do teu sistema. Fica guardado neste navegador.</x-slot>

        <div class="contiu-appearance-mode">
            <x-filament-panels::theme-switcher />
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Paleta de cores</x-slot>
        <x-slot name="description">Muda a barra de navegação, os botões e o fundo. Fica guardada na tua conta e acompanha-te em qualquer dispositivo.</x-slot>

        <div class="contiu-palette-grid" role="radiogroup" aria-label="Paletas Contiu">
            @foreach ($builtIn as $palette)
                <button
                    type="button"
                    role="radio"
                    aria-checked="{{ $palette->id === $current->id ? 'true' : 'false' }}"
                    wire:click="selectPalette('{{ $palette->id }}')"
                    wire:key="palette-{{ $palette->id }}"
                    class="contiu-palette-card"
                >
                    <span class="contiu-palette-thumb" style="{{ $thumbnail($palette) }}" aria-hidden="true">
                        <span class="contiu-palette-thumb-bar"><i></i><i></i><i></i></span>
                        <span class="contiu-palette-thumb-page"><span><i></i><i></i><b></b></span></span>
                    </span>
                    <span class="contiu-palette-name">{{ $palette->label }}</span>
                    <span class="contiu-palette-note">{{ $palette->id === $current->id ? 'Em uso' : $palette->description }}</span>
                </button>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">As tuas paletas</x-slot>
        <x-slot name="description">Parte de uma paleta existente e muda as cores.</x-slot>

        @if ($custom->isEmpty())
            <p class="contiu-palette-empty">Ainda não criaste nenhuma paleta.</p>
        @else
            <div class="contiu-palette-grid" role="radiogroup" aria-label="As tuas paletas">
                @foreach ($custom as $record)
                    @php($palette = $record->toPalette())
                    <div class="contiu-palette-own" wire:key="palette-{{ $palette->id }}">
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $palette->id === $current->id ? 'true' : 'false' }}"
                            wire:click="selectPalette('{{ $palette->id }}')"
                            class="contiu-palette-card"
                        >
                            <span class="contiu-palette-thumb" style="{{ $thumbnail($palette) }}" aria-hidden="true">
                                <span class="contiu-palette-thumb-bar"><i></i><i></i><i></i></span>
                                <span class="contiu-palette-thumb-page"><span><i></i><i></i><b></b></span></span>
                            </span>
                            <span class="contiu-palette-name">{{ $palette->label }}</span>
                            <span class="contiu-palette-note">{{ $palette->id === $current->id ? 'Em uso' : $palette->description }}</span>
                        </button>

                        <div class="contiu-palette-own-actions">
                            {{ ($this->editPaletteAction)(['palette' => $record->getKey()]) }}
                            {{ ($this->deletePaletteAction)(['palette' => $record->getKey()]) }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="contiu-palette-create">
            {{ $this->createPaletteAction }}
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</div>
