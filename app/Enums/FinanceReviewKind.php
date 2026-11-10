<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FinanceReviewKind: string implements HasLabel
{
    case Comment = 'comment';
    case EvidenceRequest = 'evidence_request';

    public function getLabel(): string
    {
        return match ($this) {
            self::Comment => 'Comentário',
            self::EvidenceRequest => 'Pedido de comprovativo',
        };
    }
}
