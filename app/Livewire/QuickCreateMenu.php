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

    /**
     * True for the copy in the phone tab bar: a round "+" whose menu opens upwards. The topbar
     * copy (the default) is a "Novo" button whose menu opens downwards.
     */
    public bool $tabBar = false;

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
        $menu = ActionGroup::make([
            $this->recordPaymentAction(),
            $this->oneOffIncomeAction(),
            Action::make('newExpense')
                ->label(__('New expense'))
                ->icon(Phosphor::Receipt)
                ->url(ExpenseResource::getUrl('create')),
            Action::make('newProject')
                ->label(__('New project'))
                ->icon(Phosphor::Briefcase)
                ->url(ProjectResource::getUrl('create')),
            Action::make('newRetainer')
                ->label(__('New retainer'))
                ->icon(Phosphor::Repeat)
                ->url(RetainerResource::getUrl('create')),
            Action::make('newClient')
                ->label(__('New client'))
                ->icon(Phosphor::UserPlus)
                ->url(ClientResource::getUrl()),
        ])
            ->label(__('New'))
            ->icon(Phosphor::Plus)
            ->color('primary');

        return $this->tabBar
            ? $menu
                ->iconButton()
                ->size('xl')
                ->dropdownPlacement('top')
                ->extraAttributes(['class' => 'fi-quick-create-fab'])
            : $menu
                ->button()
                ->size('sm')
                ->dropdownPlacement('bottom-end')
                ->extraAttributes(['class' => 'fi-quick-create-btn']);
    }

    public function render(): View
    {
        return view('livewire.quick-create-menu');
    }
}
