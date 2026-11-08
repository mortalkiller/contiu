<?php

namespace App\Mail\Concerns;

use App\Models\Account;
use App\Support\CurrentAccount;
use Illuminate\Mail\Mailables\Address;

/**
 * Client emails go out from the app address but belong to the account: signed with its name,
 * and replies land with its owner. The account is the current one at send time (panel tenant).
 */
trait SendsAsAccount
{
    protected function account(): ?Account
    {
        return CurrentAccount::get();
    }

    protected function accountName(): string
    {
        return $this->account()?->name ?? config('app.name');
    }

    /** @return array<int, Address> */
    protected function replyToOwner(): array
    {
        $email = $this->account()?->owner()?->email;

        return $email ? [new Address($email)] : [];
    }
}
