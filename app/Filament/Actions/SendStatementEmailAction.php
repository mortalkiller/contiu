<?php

namespace App\Filament\Actions;

use App\Exports\ClientStatementExport;
use App\Mail\ClientStatementEmail;
use App\Models\ClientMessage;
use App\Support\ClientStatement;
use App\Support\CurrentAccount;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Mail;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Enviar extrato por email": the statement for the current filter range, attached as .xlsx,
 * sent only after Pedro reviews the email.
 */
class SendStatementEmailAction extends Action
{
    protected ?Closure $statementResolver = null;

    public static function getDefaultName(): ?string
    {
        return 'sendStatement';
    }

    public function statement(Closure $resolver): static
    {
        $this->statementResolver = $resolver;

        return $this;
    }

    protected function resolveStatement(): ClientStatement
    {
        return $this->evaluate($this->statementResolver);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Email statement'))
            ->icon(Phosphor::PaperPlaneTilt)
            ->color('gray')
            ->modalHeading(__('Email statement'))
            ->modalDescription(__('The Excel attachment covers the selected period. Review the email before sending.'))
            ->modalIcon(Phosphor::PaperPlaneTilt)
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(__('Send'))
            // Emails to clients are a write too: none from a read-only account.
            ->disabled(fn () => CurrentAccount::readOnly() || blank($this->resolveStatement()->client->email))
            ->tooltip(fn () => match (true) {
                CurrentAccount::readOnly() => __('The account is read-only'),
                blank($this->resolveStatement()->client->email) => __('The client has no email address'),
                default => null,
            })
            ->fillForm(function (): array {
                $statement = $this->resolveStatement();
                $client = $statement->client;
                $locale = $client->preferredLanguage()->locale();

                return [
                    'to' => $client->email,
                    'subject' => __('Account statement — :name', ['name' => $client->name], $locale),
                    'message' => implode("\n", [
                        __('Hello :name,', ['name' => $client->name], $locale),
                        '',
                        __('Your account statement is attached, with a balance of :balance.', ['balance' => Money::format($statement->balance(), $locale)], $locale),
                        '',
                        __('Please contact us if you have any questions.', [], $locale),
                    ]),
                ];
            })
            ->schema([
                TextInput::make('to')->label(__('To'))->email()->required(),
                TextInput::make('subject')->label(__('Subject'))->required()->maxLength(200),
                Textarea::make('message')->label(__('Message'))->rows(6)->required(),
            ])
            ->action(function (array $data): void {
                $statement = $this->resolveStatement();

                Mail::to($data['to'])->send(new ClientStatementEmail($statement, subjectLine: $data['subject'], body: $data['message']));

                ClientMessage::create([
                    'client_id' => $statement->client->getKey(),
                    'type' => ClientMessage::STATEMENT,
                    'to' => $data['to'],
                    'subject' => $data['subject'],
                    'body' => $data['message'],
                    'user_id' => auth()->id(),
                    'sent_at' => now(),
                ]);

                Notification::make()
                    ->title(__('Statement sent to :email', ['email' => $data['to']]))
                    ->body(ClientStatementExport::filename($statement))
                    ->success()
                    ->send();
            });
    }
}
