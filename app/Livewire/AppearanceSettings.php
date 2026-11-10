<?php

namespace App\Livewire;

use App\Models\User;
use App\Models\UserPalette;
use App\Support\Appearance\Palette;
use App\Support\Appearance\PaletteRegistry;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Perfil → Aparência: light/dark mode, the colour palette, and the user's own palettes.
 * The choice is saved on the user and follows them into every account. Colours are set in the
 * page head, so the page reloads after each change.
 */
class AppearanceSettings extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** A user keeps a handful of palettes, not a library. */
    public const int MAX_CUSTOM_PALETTES = 12;

    private const string HEX_COLOR = '/^#[0-9a-fA-F]{6}$/';

    public function selectPalette(string $key): void
    {
        $user = $this->user();

        if (PaletteRegistry::find($key, $user) === null) {
            return;
        }

        $user->palette = $key === PaletteRegistry::DEFAULT ? null : $key;
        $user->save();

        $this->reloadPage();
    }

    public function createPaletteAction(): Action
    {
        return Action::make('createPalette')
            ->label('Criar paleta')
            ->icon(Phosphor::Plus)
            ->color('gray')
            ->visible(fn (): bool => $this->user()->palettes()->count() < self::MAX_CUSTOM_PALETTES)
            ->modalHeading('Criar paleta')
            ->modalDescription('Três cores chegam. As cores de estado (pago, por tratar, vencido) são iguais em todas as paletas.')
            ->modalSubmitActionLabel('Guardar e usar')
            ->modalWidth('lg')
            ->fillForm(fn (): array => $this->colorsOf(PaletteRegistry::current()) + ['base' => PaletteRegistry::current()->id])
            ->schema([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(40),
                Select::make('base')
                    ->label('Começar a partir de')
                    ->options(fn (): array => $this->paletteOptions())
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $base = PaletteRegistry::find($state, $this->user());

                        foreach ($base === null ? [] : $this->colorsOf($base) as $field => $color) {
                            $set($field, $color);
                        }
                    })
                    ->dehydrated(false),
                ...$this->colorFields(),
            ])
            ->action(function (array $data): void {
                $palette = $this->user()->palettes()->create($data);

                $this->selectPalette($palette->key());
            });
    }

    public function editPaletteAction(): Action
    {
        return Action::make('editPalette')
            ->label('Editar')
            ->icon(Phosphor::PencilSimple)
            ->iconButton()
            ->color('gray')
            ->modalHeading(fn (array $arguments): string => 'Editar paleta «'.$this->customPalette($arguments)->name.'»')
            ->modalSubmitActionLabel('Guardar paleta')
            ->modalWidth('lg')
            ->fillForm(fn (array $arguments): array => $this->customPalette($arguments)->only(['name', 'primary_color', 'navigation_color', 'background_color']))
            ->schema([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(40),
                ...$this->colorFields(),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->customPalette($arguments)->update($data);

                $this->reloadPage();
            });
    }

    public function deletePaletteAction(): Action
    {
        return Action::make('deletePalette')
            ->label('Eliminar')
            ->icon(Phosphor::Trash)
            ->iconButton()
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Eliminar a paleta «'.$this->customPalette($arguments)->name.'»?')
            ->modalDescription('Se for a que estás a usar, voltas à paleta Contiu.')
            ->modalSubmitActionLabel('Eliminar paleta')
            ->action(function (array $arguments): void {
                $user = $this->user();
                $palette = $this->customPalette($arguments);

                if ($user->palette === $palette->key()) {
                    $user->palette = null;
                    $user->save();
                }

                $palette->delete();

                Notification::make()->title('Paleta eliminada')->success()->send();

                $this->reloadPage();
            });
    }

    public function render(): View
    {
        return view('filament.account.appearance-settings', [
            'current' => PaletteRegistry::current(),
            'builtIn' => PaletteRegistry::builtIn(),
            'custom' => $this->user()->palettes()->orderBy('name')->get(),
        ]);
    }

    /**
     * The three colours a custom palette is made of.
     *
     * @return list<ColorPicker>
     */
    private function colorFields(): array
    {
        return [
            ColorPicker::make('primary_color')
                ->label('Cor principal')
                ->helperText('Botão principal, ligações e gráficos.')
                ->required()
                ->regex(self::HEX_COLOR),
            ColorPicker::make('navigation_color')
                ->label('Barra de navegação')
                ->helperText('O texto fica branco ou preto, conforme o que se ler melhor.')
                ->required()
                ->regex(self::HEX_COLOR),
            ColorPicker::make('background_color')
                ->label('Fundo')
                ->helperText('Por trás dos cartões e das tabelas, no modo claro.')
                ->required()
                ->regex(self::HEX_COLOR),
        ];
    }

    /**
     * @return array{primary_color: string, navigation_color: string, background_color: string}
     */
    private function colorsOf(Palette $palette): array
    {
        return [
            'primary_color' => $palette->action,
            'navigation_color' => $palette->navigation,
            'background_color' => $palette->background,
        ];
    }

    /**
     * Built-in palettes and the user's own, for "Começar a partir de".
     *
     * @return array<string, string>
     */
    private function paletteOptions(): array
    {
        $options = array_map(fn (Palette $palette): string => $palette->label, PaletteRegistry::builtIn());

        foreach ($this->user()->palettes()->orderBy('name')->get() as $palette) {
            $options[$palette->key()] = $palette->name;
        }

        return $options;
    }

    /**
     * The palette an edit or delete action was opened for. Only ever one of the user's own.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function customPalette(array $arguments): UserPalette
    {
        return $this->user()->palettes()->findOrFail($arguments['palette'] ?? null);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function reloadPage(): void
    {
        $this->js('window.location.reload()');
    }
}
