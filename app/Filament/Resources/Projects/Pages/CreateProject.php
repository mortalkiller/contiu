<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\Language;
use App\Filament\Resources\Concerns\HasSaveBar;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Support\InstalmentPlanner;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Novo trabalho", as in the mock: the same single-page form as the edit page, with quick plans
 * that fill the instalments from the total.
 */
class CreateProject extends CreateRecord
{
    use HasSaveBar;

    protected static string $resource = ProjectResource::class;

    public function getTitle(): string
    {
        return __('New project');
    }

    /** "Novo trabalho" from a client page arrives with ?client=ID. */
    protected function afterFill(): void
    {
        if ($client = request()->integer('client')) {
            $this->data['client_id'] = $client;
        }
    }

    public function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema, $this->planPresetActions());
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
                ->label(__('Single payment'))
                ->icon(Phosphor::Coin)
                ->color('gray')
                ->size('sm')
                ->action(fn (Get $get, Set $set) => $apply($set, InstalmentPlanner::single(ProjectForm::totalFrom($get), $firstDue($get)))),
            Action::make('planHalves')
                ->label(__('50% / 50%'))
                ->icon(Phosphor::CircleHalf)
                ->color('gray')
                ->size('sm')
                ->action(fn (Get $get, Set $set) => $apply($set, InstalmentPlanner::halves(ProjectForm::totalFrom($get), $firstDue($get)))),
            Action::make('planEqual')
                ->label(__('3 equal instalments'))
                ->icon(Phosphor::ListNumbers)
                ->color('gray')
                ->size('sm')
                ->action(fn (Get $get, Set $set) => $apply($set, InstalmentPlanner::equal(ProjectForm::totalFrom($get), 3, $firstDue($get)))),
            Action::make('planMonthly')
                ->label(__('Monthly until…'))
                ->icon(Phosphor::CalendarDots)
                ->color('gray')
                ->size('sm')
                ->modalHeading(__('Monthly instalments'))
                ->modalDescription(__('Divide the total into equal monthly instalments from the quote date to the final due date.'))
                ->modalWidth(Width::Small)
                ->modalSubmitActionLabel(__('Create plan'))
                ->schema([
                    DatePicker::make('last_due_on')
                        ->label(__('Final due date'))
                        ->native(false)
                        ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                        ->required(),
                ])
                ->action(fn (array $data, Get $get, Set $set) => $apply($set, InstalmentPlanner::monthlyUntil(ProjectForm::totalFrom($get), $firstDue($get), $data['last_due_on']))),
        ];
    }
}
