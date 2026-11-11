<?php

namespace App\Filament\Actions;

use App\Enums\Language;
use App\Mail\PaymentReminder;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Receivable;
use App\Support\CurrentAccount;
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
            ->label(__('Send reminder'))
            ->icon(Phosphor::EnvelopeSimple)
            ->color('gray')
            ->modalHeading(fn (?Model $record, $livewire) => __('Payment reminder — :name', ['name' => static::clientFor($record, $livewire)?->name ?? '']))
            ->modalDescription(__('Review the email before sending.'))
            ->modalIcon(Phosphor::EnvelopeSimple)
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(__('Send'))
            ->visible(fn (?Model $record, $livewire) => static::clientFor($record, $livewire)?->openReceivables()->isNotEmpty() ?? false)
            // Emails to clients are a write too: none from a read-only account.
            ->disabled(fn (?Model $record, $livewire) => CurrentAccount::readOnly() || blank(static::clientFor($record, $livewire)?->email))
            ->tooltip(fn (?Model $record, $livewire) => match (true) {
                CurrentAccount::readOnly() => __('The account is read-only'),
                blank(static::clientFor($record, $livewire)?->email) => __('The client has no email address'),
                default => null,
            })
            ->fillForm(function (?Model $record, $livewire): array {
                $client = static::clientFor($record, $livewire);

                return [
                    'to' => $client?->email,
                    'subject' => $client ? __('Payment reminder — :name', ['name' => $client->name], $client->preferredLanguage()->locale()) : null,
                    'message' => $client ? static::defaultMessage($client) : null,
                ];
            })
            ->schema(fn (?Model $record, $livewire) => [
                TextInput::make('to')->label(__('To'))->email()->required(),
                TextInput::make('subject')->label(__('Subject'))->required()->maxLength(200),
                Textarea::make('message')->label(__('Message'))->rows(8)->required(),
                TextEntry::make('items')
                    ->label(__('Outstanding amounts included in the email'))
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

                Notification::make()->title(__('Reminder sent to :email', ['email' => $data['to']]))->success()->send();
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

        $locale = $client->preferredLanguage()->locale();
        $lines = [__('Hello :name,', ['name' => $client->name], $locale), '', __('We hope you are well.', [], $locale), ''];

        if ($overdue->isNotEmpty()) {
            $lines[] = trans_choice(
                '{1} There is one overdue amount, totaling :total.|[2,*] There are :count overdue amounts, totaling :total.',
                $overdue->count(),
                ['total' => Money::format($overdueTotal, $locale)],
                $locale,
            );
        }

        $lines[] = __('A summary of the outstanding amounts is below. If you have already paid, please disregard this message.', [], $locale);
        $lines[] = '';
        $lines[] = __('Thank you.', [], $locale);

        return implode("\n", $lines);
    }

    private static function itemsPreview(?Client $client, ?int $highlightedId): HtmlString
    {
        if ($client === null) {
            return new HtmlString('—');
        }

        $rows = $client->openReceivables()->map(function (Receivable $receivable) use ($highlightedId) {
            $text = e(trim(($receivable->billable?->code ?? '').' · '.$receivable->description, ' ·'))
                .' — '.e(__('due :date', ['date' => $receivable->due_on->format(Language::resolve(app()->getLocale())->dateFormat())]))
                .($receivable->due_on->isBefore(today()) ? ' <strong>'.e(__('(overdue)')).'</strong>' : '')
                .' — '.e(Money::format($receivable->outstanding));

            return '<li>'.($receivable->getKey() === $highlightedId ? '<strong>'.$text.'</strong>' : $text).'</li>';
        })->implode('');

        return new HtmlString('<ul class="list-disc ps-5">'.$rows.'</ul>');
    }
}
