<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use ToneGabes\Filament\Icons\Enums\Phosphor;

enum ProjectStatus: string implements HasColor, HasIcon, HasLabel
{
    case Proposal = 'proposal';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proposal => __('Proposal'),
            self::Approved => __('Approved'),
            self::InProgress => __('In progress'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Canceled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposal => 'gray',
            self::Approved => 'info',
            self::InProgress => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): Phosphor
    {
        return match ($this) {
            self::Proposal => Phosphor::FileText,
            self::Approved => Phosphor::Handshake,
            self::InProgress => Phosphor::Hammer,
            self::Completed => Phosphor::CheckCircle,
            self::Cancelled => Phosphor::XCircle,
        };
    }
}
