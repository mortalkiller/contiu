<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\Client;
use Filament\Schemas\Schema;
use MortalKiller\FilamentPageHeader\Components\Header;
use MortalKiller\FilamentPageHeader\Enums\AvatarShape;
use MortalKiller\FilamentPageHeader\Enums\AvatarStyle;

/**
 * Who the client is, over the page: initials, name and, right under it, one line with the tax
 * number, the email and since when they are a client. The figures sit under the header
 * (ClientSummary) and the page's actions at its end.
 */
class ClientHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            static::header()
                ->description(fn (Client $record): string => static::identityLine($record), html: true),
        ]);
    }

    /** The client's header without its second line, for a page that says something else there. */
    public static function header(): Header
    {
        return Header::make()
            // On the page itself, as in the mock: the figures under it are the card.
            ->contained(false)
            ->heading(fn (Client $record) => $record->name)
            ->initials(fn (Client $record) => $record->name)
            // A rounded square tinted with the user's palette colour.
            ->initialsBgColor('primary')
            ->avatarShape(AvatarShape::Rounded)
            ->avatarStyle(AvatarStyle::Soft);
    }

    /**
     * "NIF 509999990 · geral@estudio.example · Cliente desde março 2026", as HTML. The tax number
     * is always there, in a monospaced font, with a dash while it is missing; the email only when
     * there is one. Every value is escaped.
     */
    public static function identityLine(Client $client): string
    {
        $taxId = filled($client->vat_number)
            ? '<span class="font-mono text-[0.8125rem]">'.e($client->vat_number).'</span>'
            : '—';

        return implode(' · ', array_filter([
            __('Tax ID :number', ['number' => $taxId]),
            filled($client->email) ? e($client->email) : null,
            e(__('Client since :date', ['date' => $client->created_at->locale(app()->getLocale())->translatedFormat('F Y')])),
        ]));
    }
}
