<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Models\Supplier;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use MortalKiller\FilamentPageHeader\Components\Header;
use MortalKiller\FilamentPageHeader\Enums\AvatarShape;
use MortalKiller\FilamentPageHeader\Enums\AvatarStyle;

/**
 * Who the supplier is, over its page (discovered by convention): initials, name and, right under
 * it, one line with the tax number, the website and since when you buy from them. The figures
 * sit under the header (SupplierSummary) and the page's actions at its end.
 */
class SupplierHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                // On the page itself, as in the mock: the figures under it are the card.
                ->contained(false)
                ->heading(fn (Supplier $record) => $record->name)
                ->initials(fn (Supplier $record) => $record->name)
                // A rounded square tinted with the user's palette colour.
                ->initialsBgColor('primary')
                ->avatarShape(AvatarShape::Rounded)
                ->avatarStyle(AvatarStyle::Soft)
                ->description(fn (Supplier $record): string => static::identityLine($record), html: true),
        ]);
    }

    /**
     * "NIF 509999990 · lojademo.example · Fornecedor desde maio 2025", as HTML. The tax number is
     * always there, in a monospaced font, with a dash while it is missing; the website only when
     * there is one. Every value is escaped.
     */
    public static function identityLine(Supplier $supplier): string
    {
        $taxId = filled($supplier->vat_number)
            ? '<span class="font-mono text-[0.8125rem]">'.e($supplier->vat_number).'</span>'
            : '—';
        $since = $supplier->expenses()->min('spent_on') ?? $supplier->created_at;

        return implode(' · ', array_filter([
            __('Tax ID :number', ['number' => $taxId]),
            filled($supplier->website) ? e(preg_replace('#^https?://(www\.)?#', '', (string) $supplier->website)) : null,
            e(__('Supplier since :date', ['date' => Carbon::parse($since)->locale(app()->getLocale())->translatedFormat('F Y')])),
        ]));
    }
}
