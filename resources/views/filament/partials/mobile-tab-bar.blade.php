{{--
    Phone and tablet only (hidden from 1024px up, see theme.css).

    The tab bar has the three places opened most often, "Novo" in the middle, and "Mais", which
    opens a sheet with the rest of the menu. The sheet is built from the panel's navigation by
    App\Support\MobileMenu: open groups are tiles, collapsed groups are a row that opens a list.
    While this bar is on the page the topbar drops its own menu button and "Novo" button.
--}}
@php
    use App\Filament\Pages\Dashboard;
    use App\Filament\Resources\Expenses\ExpenseResource;
    use App\Filament\Resources\Receivables\ReceivableResource;
    use App\Support\MobileMenu;
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    use function Filament\Support\generate_icon_html;

    $home = Dashboard::getUrl();

    $tabs = [
        ['label' => 'Hoje', 'icon' => Phosphor::House, 'url' => $home, 'active' => request()->url() === $home],
        ['label' => 'Receber', 'icon' => Phosphor::HourglassMedium, 'url' => ReceivableResource::getUrl(), 'active' => request()->fullUrlIs(ReceivableResource::getUrl().'*')],
        ['label' => 'Despesas', 'icon' => Phosphor::Receipt, 'url' => ExpenseResource::getUrl(), 'active' => request()->fullUrlIs(ExpenseResource::getUrl().'*')],
    ];

    $sections = MobileMenu::sections(excludedUrls: array_column($tabs, 'url'));
@endphp

<div
    class="contiu-mobile-nav"
    x-data="{
        open: false,
        group: null,
        query: '',
        matches(label) {
            return this.query.trim() === '' || label.toLowerCase().includes(this.query.trim().toLowerCase())
        },
        close() {
            this.open = false
            this.query = ''
        },
    }"
    x-effect="document.body.classList.toggle('contiu-mobile-menu-open', open)"
    x-on:keydown.escape.window="close()"
>
    <div class="contiu-menu-backdrop" x-show="open" x-transition.opacity x-on:click="close()" x-cloak></div>

    <section
        id="contiu-mobile-menu"
        class="contiu-menu-sheet"
        role="dialog"
        aria-modal="true"
        aria-label="Menu"
        x-show="open"
        x-transition
        x-cloak
    >
        <span class="contiu-menu-grabber" aria-hidden="true"></span>

        <label class="contiu-menu-search">
            {{ generate_icon_html(Phosphor::MagnifyingGlass) }}
            <input type="search" x-model="query" placeholder="Ir para…" aria-label="Pesquisar no menu">
        </label>

        @foreach ($sections as $section)
            @php($labels = array_column($section['items'], 'label'))

            @if ($section['collapsed'])
                {{-- A collapsed group: one row; its pages open underneath, or show while searching. --}}
                <div
                    class="contiu-menu-group"
                    x-show="@js($labels).some((label) => matches(label))"
                >
                    <button
                        type="button"
                        class="contiu-menu-row"
                        x-on:click="group = group === @js($section['label']) ? null : @js($section['label'])"
                        x-bind:aria-expanded="group === @js($section['label']) || query.trim() !== ''"
                    >
                        <span>{{ $section['label'] }}</span>
                        <span class="contiu-menu-row-end">
                            {{ count($section['items']) }} {{ count($section['items']) === 1 ? 'página' : 'páginas' }}
                            {{ generate_icon_html(Phosphor::CaretDown) }}
                        </span>
                    </button>

                    <div class="contiu-menu-list" x-show="group === @js($section['label']) || query.trim() !== ''" x-cloak>
                        @foreach ($section['items'] as $item)
                            <a
                                href="{{ $item['url'] }}"
                                class="contiu-menu-link"
                                x-show="matches(@js($item['label']))"
                                @if ($item['active']) aria-current="page" @endif
                            >
                                {{ generate_icon_html($item['icon']) }}
                                <span>{{ $item['label'] }}</span>
                                @if (filled($item['badge']))
                                    <span class="contiu-menu-badge" data-color="{{ $item['badgeColor'] }}">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @else
                {{-- An open group: its pages as tiles. --}}
                <div x-show="@js($labels).some((label) => matches(label))">
                    @if ($section['label'] !== null)
                        <h2 class="contiu-menu-heading">{{ $section['label'] }}</h2>
                    @endif

                    <div class="contiu-menu-tiles">
                        @foreach ($section['items'] as $item)
                            <a
                                href="{{ $item['url'] }}"
                                class="contiu-menu-tile"
                                x-show="matches(@js($item['label']))"
                                @if ($item['active']) aria-current="page" @endif
                            >
                                @if (filled($item['badge']))
                                    <span class="contiu-menu-badge" data-color="{{ $item['badgeColor'] }}">{{ $item['badge'] }}</span>
                                @endif
                                {{ generate_icon_html($item['icon']) }}
                                <span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </section>

    <nav class="contiu-tab-bar" aria-label="Atalhos">
        @foreach (array_slice($tabs, 0, 2) as $tab)
            <a href="{{ $tab['url'] }}" class="contiu-tab-bar-item" @if ($tab['active']) aria-current="page" @endif>
                {{ generate_icon_html($tab['icon']) }}
                <span>{{ $tab['label'] }}</span>
            </a>
        @endforeach

        <div class="contiu-tab-bar-new">
            @livewire(\App\Livewire\QuickCreateMenu::class, ['tabBar' => true], key('quick-create-tab-bar'))
        </div>

        @foreach (array_slice($tabs, 2) as $tab)
            <a href="{{ $tab['url'] }}" class="contiu-tab-bar-item" @if ($tab['active']) aria-current="page" @endif>
                {{ generate_icon_html($tab['icon']) }}
                <span>{{ $tab['label'] }}</span>
            </a>
        @endforeach

        <button
            type="button"
            class="contiu-tab-bar-item"
            x-on:click="open ? close() : (open = true)"
            x-bind:aria-expanded="open"
            x-bind:data-open="open"
            aria-controls="contiu-mobile-menu"
        >
            <span class="contiu-tab-bar-more" x-show="! open">{{ generate_icon_html(Phosphor::List) }}</span>
            <span class="contiu-tab-bar-more" x-show="open" x-cloak>{{ generate_icon_html(Phosphor::X) }}</span>
            <span x-text="open ? 'Fechar' : 'Mais'">Mais</span>
        </button>
    </nav>
</div>
