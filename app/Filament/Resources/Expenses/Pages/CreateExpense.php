<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Pages\ExpenseDocumentImport;
use App\Filament\Resources\Concerns\HasSaveBar;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\ExpenseEvidence;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class CreateExpense extends CreateRecord
{
    use HasSaveBar;

    protected static string $resource = ExpenseResource::class;

    /** "Nova despesa" on a supplier's page opens the form with that supplier already chosen. */
    protected function afterFill(): void
    {
        $supplier = request()->integer('supplier');

        if ($supplier > 0 && Supplier::query()->whereKey($supplier)->exists()) {
            $this->data['supplier_id'] = $supplier;
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ExpenseForm::normalize($data);
    }

    protected function handleRecordCreation(array $data): Expense
    {
        return ExpenseEvidence::save(new Expense, $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    public function getTitle(): string
    {
        return __('New expense');
    }

    /** As in the mock: the way to have the fields filled in from the invoice. */
    public function getSubheading(): ?Htmlable
    {
        if (! ExpenseDocumentImport::canAccess()) {
            return null;
        }

        return new HtmlString(__('Have the invoice as a PDF? :link and Contiu fills in these fields.', [
            'link' => '<a href="'.e(ExpenseDocumentImport::getUrl()).'" class="font-medium text-primary-600 underline underline-offset-2 dark:text-primary-400">'.e(__('Import it')).'</a>',
        ]));
    }

    protected function saveAndCreateAnotherLabel(): string
    {
        return __('Save and create another expense');
    }
}
