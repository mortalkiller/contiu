<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Models\Client;
use App\Support\InstalmentPlanner;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Novo trabalho" in four steps: client → job and value → payment plan (with presets) → summary.
 * The edit page keeps the full single-page form (ProjectForm::configure).
 */
class CreateProject extends CreateRecord
{
    use HasWizard;

    protected static string $resource = ProjectResource::class;

    protected static ?string $title = 'Novo trabalho';

    /** "Novo trabalho" from a client page arrives with ?client=ID. */
    protected function afterFill(): void
    {
        if ($client = request()->integer('client')) {
            $this->data['client_id'] = $client;
        }
    }

    protected function getSteps(): array
    {
        return [
            Step::make('Cliente')
                ->description('Para quem é')
                ->icon(Phosphor::User)
                ->columns(2)
                ->schema(ProjectForm::clientFields()),

            Step::make('Trabalho')
                ->description('O quê e quanto')
                ->icon(Phosphor::Briefcase)
                ->columns(3)
                ->schema([
                    Grid::make(2)
                        ->columnSpan(2)
                        ->schema([
                            ProjectForm::codeField(),
                            ProjectForm::statusField(),
                            ...ProjectForm::projectFields(),
                        ]),
                    Grid::make(1)
                        ->columnSpan(1)
                        ->schema(ProjectForm::valueFields()),
                ]),

            Step::make('Plano de pagamento')
                ->description('Quando recebes')
                ->icon(Phosphor::CalendarBlank)
                ->schema([
                    Actions::make($this->planPresetActions())
                        ->key('planPresets'),
                    ...ProjectForm::planFields(),
                ]),

            Step::make('Resumo')
                ->description('Confirmar')
                ->icon(Phosphor::CheckCircle)
                ->columns(2)
                ->schema([
                    TextEntry::make('summary_client')
                        ->label('Cliente')
                        ->state(fn (Get $get) => Client::find($get('client_id'))?->name ?? '—'),
                    TextEntry::make('summary_total')
                        ->label('Total')
                        ->state(fn (Get $get) => Money::format(ProjectForm::totalFrom($get))),
                    TextEntry::make('summary_code')
                        ->label('Trabalho')
                        ->state(fn (Get $get) => trim($get('code').' — '.$get('title'), ' —')),
                    TextEntry::make('summary_quoted_on')
                        ->label('Data do orçamento')
                        ->state(fn (Get $get) => filled($get('quoted_on')) ? Carbon::parse($get('quoted_on'))->format('d/m/Y') : '—'),
                    TextEntry::make('summary_plan')
                        ->label('Plano de pagamento')
                        ->columnSpanFull()
                        ->state(fn (Get $get) => $this->planSummary($get('receivables'))),
                ]),
        ];
    }

    /**
     * Quick plans that fill the repeater from the current total; the first instalment falls on the
     * quote date (or today).
     *
     * @return array<int, Action>
     */
    protected function planPresetActions(): array
    {
        $apply = function (Set $set, array $rows): void {
            // The planner works in cents; the repeater holds euros.
            $set('receivables', collect($rows)->mapWithKeys(fn (array $row) => [(string) Str::uuid() => [...$row, 'amount' => Money::toEuros($row['amount'])]])->all());
        };

        $firstDue = fn (Get $get) => $get('quoted_on') ?: today()->toDateString();

        return [
            Action::make('planSingle')
                ->label('Pagamento único')
                ->icon(Phosphor::Coin)
                ->color('gray')
                ->size('sm')
                ->action(fn (Get $get, Set $set) => $apply($set, InstalmentPlanner::single(ProjectForm::totalFrom($get), $firstDue($get)))),
            Action::make('planHalves')
                ->label('50% / 50%')
                ->icon(Phosphor::CircleHalf)
                ->color('gray')
                ->size('sm')
                ->action(fn (Get $get, Set $set) => $apply($set, InstalmentPlanner::halves(ProjectForm::totalFrom($get), $firstDue($get)))),
            Action::make('planEqual')
                ->label('3 prestações iguais')
                ->icon(Phosphor::ListNumbers)
                ->color('gray')
                ->size('sm')
                ->action(fn (Get $get, Set $set) => $apply($set, InstalmentPlanner::equal(ProjectForm::totalFrom($get), 3, $firstDue($get)))),
            Action::make('planMonthly')
                ->label('Mensal até…')
                ->icon(Phosphor::CalendarDots)
                ->color('gray')
                ->size('sm')
                ->modalHeading('Prestações mensais')
                ->modalDescription('Divide o total em prestações mensais iguais, desde a data do orçamento até ao último vencimento.')
                ->modalWidth(Width::Small)
                ->modalSubmitActionLabel('Criar plano')
                ->schema([
                    DatePicker::make('last_due_on')
                        ->label('Último vencimento')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),
                ])
                ->action(fn (array $data, Get $get, Set $set) => $apply($set, InstalmentPlanner::monthlyUntil(ProjectForm::totalFrom($get), $firstDue($get), $data['last_due_on']))),
        ];
    }

    protected function planSummary(mixed $rows): HtmlString
    {
        $rows = collect(is_array($rows) ? $rows : []);

        if ($rows->isEmpty()) {
            return new HtmlString('—');
        }

        return new HtmlString('<ul class="space-y-1">'.$rows->map(fn ($row) => sprintf(
            '<li>%s — %s — vence %s</li>',
            e($row['description'] ?? ''),
            e(Money::format(Money::toCents($row['amount'] ?? null) ?? 0)),
            e(filled($row['due_on'] ?? null) ? Carbon::parse($row['due_on'])->format('d/m/Y') : '—'),
        ))->implode('').'</ul>');
    }
}
