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
            ->label('Enviar extrato por email')
            ->icon(Phosphor::PaperPlaneTilt)
            ->color('gray')
            ->modalHeading('Enviar extrato por email')
            ->modalDescription('O extrato do período filtrado segue em anexo (Excel). Revê o email antes de enviar.')
            ->modalIcon(Phosphor::PaperPlaneTilt)
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Enviar')
            // Emails to clients are a write too: none from a read-only account.
            ->disabled(fn () => CurrentAccount::readOnly() || blank($this->resolveStatement()->client->email))
            ->tooltip(fn () => match (true) {
                CurrentAccount::readOnly() => 'A conta está só de leitura',
                blank($this->resolveStatement()->client->email) => 'O cliente não tem email',
                default => null,
            })
            ->fillForm(function (): array {
                $statement = $this->resolveStatement();
                $client = $statement->client;

                return [
                    'to' => $client->email,
                    'subject' => 'Extrato de conta — '.$client->name,
                    'message' => implode("\n", [
                        'Olá '.$client->name.',',
                        '',
                        'Segue em anexo o extrato da sua conta, com um saldo de '.Money::format($statement->balance()).'.',
                        '',
                        'Qualquer dúvida, estamos ao dispor.',
                    ]),
                ];
            })
            ->schema([
                TextInput::make('to')->label('Para')->email()->required(),
                TextInput::make('subject')->label('Assunto')->required()->maxLength(200),
                Textarea::make('message')->label('Mensagem')->rows(6)->required(),
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
                    ->title('Extrato enviado para '.$data['to'])
                    ->body(ClientStatementExport::filename($statement))
                    ->success()
                    ->send();
            });
    }
}
