<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

/**
 * A tenant: one freelancer's (or company's) isolated books.
 * Deliberately NOT scoped by account: it is the thing the scope is keyed on.
 * One hasMany per business model, named as Filament tenancy looks them up (clients, payments…).
 * It is also who pays: Cashier's customer, with the trial as Cashier's "generic trial".
 */
class Account extends Model
{
    use Billable;

    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'billing_exempt' => 'boolean',
            'accountant_service_requested_at' => 'datetime',
        ];
    }

    /**
     * Full use of the account: the free trial, a valid subscription (past_due included: Stripe
     * is still trying to charge; canceled only until the paid period ends) or exempt from billing.
     * Without it the account is read-only (CurrentAccount::readOnly()).
     */
    public function hasAccess(): bool
    {
        return $this->billing_exempt || $this->onGenericTrial() || $this->subscribed('default');
    }

    /** Cashier's customer name in Stripe (and on its receipts): the account, not a person. */
    public function stripeName(): ?string
    {
        return $this->name;
    }

    /** The account has no email of its own: Stripe's receipts go to its owner. */
    public function stripeEmail(): ?string
    {
        return $this->owner()?->email;
    }

    /**
     * Paid subscription invoices (super admins only): not a business model of the account,
     * so it is not scoped (SubscriptionPayment).
     *
     * @return HasMany<SubscriptionPayment, $this>
     */
    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * The VAT Stripe adds to the subscription (config contiu.stripe_tax_rate): the plan prices are
     * without VAT. Cashier puts these on every new subscription and Checkout.
     *
     * @return list<string>
     */
    public function taxRates(): array
    {
        return array_values(array_filter([config('contiu.stripe_tax_rate')]));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function owner(): ?User
    {
        return $this->users()->wherePivot('role', 'owner')->first();
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<Supplier, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Retainer, $this> */
    public function retainers(): HasMany
    {
        return $this->hasMany(Retainer::class);
    }

    /** @return HasMany<Receivable, $this> */
    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<RecurringExpense, $this> */
    public function recurringExpenses(): HasMany
    {
        return $this->hasMany(RecurringExpense::class);
    }

    /** @return HasMany<OneOffIncome, $this> */
    public function oneOffIncomes(): HasMany
    {
        return $this->hasMany(OneOffIncome::class);
    }

    /** @return HasMany<BankTransaction, $this> */
    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /** @return HasMany<ClientMessage, $this> */
    public function clientMessages(): HasMany
    {
        return $this->hasMany(ClientMessage::class);
    }

    /** @return HasMany<Setting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }
}
