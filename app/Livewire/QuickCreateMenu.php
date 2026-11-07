<?php

namespace App\Livewire;

use App\Filament\Actions\OneOffIncomeAction;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Retainers\RetainerResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * The "+" in the topbar: the most common entries from any screen.
 */
class QuickCreateMenu extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function recordPaymentAction(): Action
    {
        return RecordPaymentAction::make();
    }

    public function oneOffIncomeAction(): Action
    {
        return OneOffIncomeAction::make();
    }

    public function menu(): ActionGroup
    {
        return ActionGroup::make([
            $this->recordPaymentAction(),
            $this->oneOffIncomeAction(),
            Action::make('newExpense')
                ->label('Nova despesa')
                ->icon(Phosphor::Receipt)
                ->url(ExpenseResource::getUrl('create')),
            Action::make('newProject')
                ->label('Novo trabalho')
                ->icon(Phosphor::Briefcase)
                ->url(ProjectResource::getUrl('create')),
            Action::make('newRetainer')
                ->label('Nova avença')
                ->icon(Phosphor::Repeat)
                ->url(RetainerResource::getUrl('create')),
            Action::make('newClient')
                ->label('Novo cliente')
                ->icon(Phosphor::UserPlus)
                ->url(ClientResource::getUrl()),
        ])
            ->label('Novo')
            ->icon(Phosphor::Plus)
            ->button()
            ->color('gray')
            ->size('sm')
            ->dropdownPlacement('bottom-end')
            ->extraAttributes(['class' => 'fi-quick-create-btn']);
    }

    public function render(): View
    {
        return view('livewire.quick-create-menu');
    }
}
