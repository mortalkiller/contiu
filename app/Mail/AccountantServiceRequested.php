<?php

namespace App\Mail;

use App\Enums\Language;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Support\AccountantService;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the super admins only: an account asked for Contiu's certified accountant
 * (AccountantService::request), so the partner's accountant can be introduced and given access.
 * Queued, and only once the request is committed.
 */
class AccountantServiceRequested extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Account $account)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Certified accountant requested: :account', ['account' => $this->account->name]),
        );
    }

    public function content(): Content
    {
        $owner = $this->account->owner();
        $service = (string) $this->account->accountant_service;

        return new Content(markdown: 'mail.accountant-service-requested', with: [
            'accountName' => $this->account->name,
            'nif' => $this->account->nif,
            'owner' => $owner === null ? null : $owner->name.' · '.$owner->email,
            'service' => ($service === 'organised' ? __('Organised accounting') : __('Simplified regime')).' · '.__(':amount a month', ['amount' => Money::format(AccountantService::priceWithVat($service === 'organised' ? 'organised' : 'simplified'))]),
            'requestedOn' => ($this->account->accountant_service_requested_at ?? now())->format(Language::resolve(app()->getLocale())->dateFormat()),
            // The mail preview may use an unsaved example account, which has no page.
            'url' => $this->account->exists ? AccountResource::getUrl('view', ['record' => $this->account], panel: 'admin', tenant: $this->account) : url('/'),
        ]);
    }
}
