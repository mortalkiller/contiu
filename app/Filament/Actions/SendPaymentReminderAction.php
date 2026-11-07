<?php

namespace App\Filament\Actions;

use App\Mail\PaymentReminder;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Enviar lembrete": prepares a payment reminder with all of the client's open amounts and sends it
 * only after Pedro reviews (and may edit) the address, subject and message. Never automatic.
 *
 * Works on a Client, on a Receivable (reminds its client, highlighting that amount) or on a page
 * whose record is a client.
 */
class SendPaymentReminderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sendPaymentReminder';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Enviar lembrete')
            ->icon(Phosphor::EnvelopeSimple)
            ->color('gray')
            ->modalHeading(fn (?Model $record, $livewire) => 'Lembrete de pagamento — '.static::clientFor($record, $livewire)?->name)
            ->modalDescription('Revê o email antes de enviar. Nada é enviado sem confirmares.')
            ->modalIcon(Phosphor::EnvelopeSimple)
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Enviar')
            ->visible(fn (?Model $record, $livewire) => static::clientFor($record, $livewire)?->openReceivables()->isNotEmpty() ?? false)
            ->disabled(fn (?Model $record, $livewire) => blank(static::clientFor($record, $livewire)?->email))
            ->tooltip(fn (?Model $record, $livewire) => blank(static::clientFor($record, $livewire)?->email) ? 'O cliente não tem email' : null)
            ->fillForm(function (?Model $record, $livewire): array {
                $client = static::clientFor($record, $livewire);

                return [
                    'to' => $client?->email,
                    'subject' => 'Lembrete de pagamento — '.$client?->name,
                    'message' => $client ? static::defaultMessage($client) : null,
                ];
            })
            ->schema(fn (?Model $record, $livewire) => [
                TextInput::make('to')->label('Para')->email()->required(),
                TextInput::make('subject')->label('Assunto')->required()->maxLength(200),
                Textarea::make('message')->label('Mensagem')->rows(8)->required(),
                TextEntry::make('items')
                    ->label('Valores em aberto incluídos no email')
                    ->state(fn () => static::itemsPreview(static::clientFor($record, $livewire), $record instanceof Receivable ? $record->getKey() : null)),
            ])
            ->action(function (array $data, ?Model $record, $livewire): void {
                $client = static::clientFor($record, $livewire);

                Mail::to($data['to'])->send(new PaymentReminder(
                    $client,
                    subjectLine: $data['subject'],
                    body: $data['message'],
                    highlightedId: $record instanceof Receivable ? $record->getKey() : null,
                ));

                ClientMessage::create([
                    'client_id' => $client->getKey(),
                    'type' => ClientMessage::PAYMENT_REMINDER,
                    'to' => $data['to'],
                    'subject' => $data['subject'],
                    'body' => $data['message'],
                    'user_id' => auth()->id(),
                    'sent_at' => now(),
                ]);

                Notification::make()->title('Lembrete enviado para '.$data['to'])->success()->send();
            });
    }

    public static function clientFor(?Model $record, mixed $livewire = null): ?Client
    {
        return match (true) {
            $record instanceof Client => $record,
            $record instanceof Receivable => $record->client,
            $livewire !== null && method_exists($livewire, 'getRecord') && $livewire->getRecord() instanceof Client => $livewire->getRecord(),
            default => null,
        };
    }

    public static function defaultMessage(Client $client): string
    {
        $open = $client->openReceivables();
        $overdue = $open->filter(fn (Receivable $receivable) => $receivable->due_on->isBefore(today()));
        $overdueTotal = $overdue->sum(fn (Receivable $receivable) => $receivable->outstanding);

        $lines = ['Olá '.$client->name.',', '', 'Esperamos que esteja tudo bem.', ''];

        if ($overdue->isNotEmpty()) {
            $lines[] = ($overdue->count() === 1
                ? 'Verificámos que há 1 valor com o prazo de pagamento ultrapassado, no total de '
                : 'Verificámos que há '.$overdue->count().' valores com o prazo de pagamento ultrapassado, no total de ')
                .Money::format($overdueTotal).'.';
        }

        $lines[] = 'Segue abaixo o resumo dos valores em aberto. Se já efetuou o pagamento, por favor ignore esta mensagem.';
        $lines[] = '';
        $lines[] = 'Obrigado.';

        return implode("\n", $lines);
    }

    private static function itemsPreview(?Client $client, ?int $highlightedId): HtmlString
    {
        if ($client === null) {
            return new HtmlString('—');
        }

        $rows = $client->openReceivables()->map(function (Receivable $receivable) use ($highlightedId) {
            $text = e(trim(($receivable->billable?->code ?? '').' · '.$receivable->description, ' ·'))
                .' — vence '.$receivable->due_on->format('d/m/Y')
                .($receivable->due_on->isBefore(today()) ? ' <strong>(em atraso)</strong>' : '')
                .' — '.e(Money::format($receivable->outstanding));

            return '<li>'.($receivable->getKey() === $highlightedId ? '<strong>'.$text.'</strong>' : $text).'</li>';
        })->implode('');

        return new HtmlString('<ul class="list-disc ps-5">'.$rows.'</ul>');
    }
}
