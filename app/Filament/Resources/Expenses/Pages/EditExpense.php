<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Concerns\HasSaveBar;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Models\Expense;
use App\Support\ExpenseEvidence;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditExpense extends EditRecord
{
    use HasSaveBar;

    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadProof')
                ->label(__('Download supporting document'))
                ->extraAttributes(['download' => true])
                ->visible(fn (Expense $record): bool => $record->hasProof() && ExpenseResource::canView($record))
                ->url(fn (Expense $record): string => ExpenseResource::getUrl('download-proof', ['record' => $record])),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ExpenseForm::normalize($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Expense
    {
        return $this->record = ExpenseEvidence::save($record, $data);
    }

    protected function afterSave(): void
    {
        $this->form->model($this->getRecord());
        $this->refreshFormData(['proof_upload', 'remove_proof']);
    }
}
