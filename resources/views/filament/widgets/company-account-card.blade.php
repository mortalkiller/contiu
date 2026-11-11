{{-- App\Filament\Widgets\CompanyAccountCard: one Filament section, built in the widget's schema. --}}
<x-filament-widgets::widget>
    {{ $this->content }}

    <x-filament-actions::modals />
</x-filament-widgets::widget>
