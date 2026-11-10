{{--
    Phone and tablet only (hidden from 1024px up, see theme.css): the four places opened most
    often plus "Novo" in the middle. "Mais" opens the full menu (the sidebar). While this bar is
    on the page the topbar drops its own menu button and "Novo" button.
--}}
@php
    use App\Filament\Pages\Dashboard;
    use App\Filament\Resources\Expenses\ExpenseResource;
    use App\Filament\Resources\Receivables\ReceivableResource;
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    use function Filament\Support\generate_icon_html;

    $home = Dashboard::getUrl();

    $items = [
        ['label' => 'Hoje', 'icon' => Phosphor::House, 'url' => $home, 'active' => request()->url() === $home],
        ['label' => 'Receber', 'icon' => Phosphor::HourglassMedium, 'url' => ReceivableResource::getUrl(), 'active' => request()->fullUrlIs(ReceivableResource::getUrl().'*')],
        ['label' => 'Despesas', 'icon' => Phosphor::Receipt, 'url' => ExpenseResource::getUrl(), 'active' => request()->fullUrlIs(ExpenseResource::getUrl().'*')],
    ];
@endphp

<nav class="contiu-tab-bar" aria-label="Atalhos">
    @foreach (array_slice($items, 0, 2) as $item)
        <a href="{{ $item['url'] }}" class="contiu-tab-bar-item" @if ($item['active']) aria-current="page" @endif>
            {{ generate_icon_html($item['icon']) }}
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach

    <div class="contiu-tab-bar-new">
        @livewire(\App\Livewire\QuickCreateMenu::class, ['tabBar' => true], key('quick-create-tab-bar'))
    </div>

    @foreach (array_slice($items, 2) as $item)
        <a href="{{ $item['url'] }}" class="contiu-tab-bar-item" @if ($item['active']) aria-current="page" @endif>
            {{ generate_icon_html($item['icon']) }}
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach

    <button
        type="button"
        class="contiu-tab-bar-item"
        x-data
        x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
        aria-controls="fi-main-sidebar"
    >
        {{ generate_icon_html(Phosphor::List) }}
        <span>Mais</span>
    </button>
</nav>
