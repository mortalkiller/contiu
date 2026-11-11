<?php

namespace App\Enums;

use App\Models\Expense;
use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Builder;

enum FinanceReviewTarget: string
{
    case Expense = 'expense';
    case FiscalDocument = 'fiscal_document';

    public function label(): string
    {
        return match ($this) {
            self::Expense => __('Expense'),
            self::FiscalDocument => __('Fiscal document'),
        };
    }

    public function foreignKey(): string
    {
        return $this->value.'_id';
    }

    /** @return Builder<Expense>|Builder<FiscalDocument> */
    public function query(): Builder
    {
        return match ($this) {
            self::Expense => Expense::query(),
            self::FiscalDocument => FiscalDocument::query(),
        };
    }
}
