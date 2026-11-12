<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * A new freelancer's account, as the sign-up wizard leaves it: owner attached, trial running,
 * fiscal settings and base categories in place. Runs outside any panel request, so everything
 * account-scoped happens inside CurrentAccount::run().
 */
class CreateAccount
{
    /** Every URL of the account carries it; the account profile holds it to the same length. */
    public const SLUG_MAX_LENGTH = 50;

    private const SETTINGS = [
        'activity_started_on', 'vat_exempt', 'vat_exemption_threshold',
        'ss_rate', 'ss_exemption_reason', 'account_names', 'has_business_account',
        'activity_start_kind', 'previous_activity_ended_on', 'ss_enrolled_from', 'dependent_income_years',
        'irs_coefficient', 'irs_jovem', 'birth_date', 'irs_jovem_year', 'ss_contribution_exempt',
        'reverse_charge_vat_rate', 'activity_opened', 'accounting_regime', 'sells_goods', 'cae', 'secondary_caes',
    ];

    public function __construct(private SeedAccountDefaults $seedDefaults) {}

    /** @param array<string, mixed> $data */
    public function handle(User $owner, array $data): Account
    {
        return DB::transaction(function () use ($owner, $data): Account {
            $account = Account::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'nif' => $data['nif'],
                'trial_ends_at' => now()->addDays((int) config('contiu.trial_days')),
                'billing_exempt' => false, // only accounts created by hand (Pedro's) skip billing
            ]);

            $account->users()->attach($owner, ['role' => 'owner']);

            CurrentAccount::run($account, fn () => app(FinanceSettings::class)->set([
                ...Arr::only($data, self::SETTINGS),
                // A VAT-exempt freelancer's payments start exempt; otherwise every new one is taxed.
                'payments_exempt_by_default' => (bool) ($data['vat_exempt'] ?? false),
            ]));

            $this->seedDefaults->handle($account);

            return $account;
        });
    }

    /** From the name, never a reserved word or a root route, with "-2", "-3"… when taken. */
    private function uniqueSlug(string $name): string
    {
        // Kept short: it is in every URL of the account, and the account profile takes 50 at most.
        $base = Str::slug($name) ?: 'conta';
        $slug = $this->fit($base);

        for ($suffix = 2; $this->isTaken($slug); $suffix++) {
            $slug = $this->fit($base, "-{$suffix}");
        }

        return $slug;
    }

    private function fit(string $base, string $suffix = ''): string
    {
        return (rtrim(Str::substr($base, 0, self::SLUG_MAX_LENGTH - strlen($suffix)), '-') ?: 'conta').$suffix;
    }

    /**
     * Free for an account's URL: not a reserved word, not a root route, not another account's.
     * Also checked when a super admin renames an account (EditAccountProfile), which passes it.
     */
    public function slugIsAvailable(string $slug, ?Account $except = null): bool
    {
        return ! in_array($slug, config('contiu.reserved_slugs'), true)
            && ! in_array($slug, $this->rootSegments(), true)
            && ! Account::query()->where('slug', $slug)->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))->exists();
    }

    private function isTaken(string $slug): bool
    {
        return ! $this->slugIsAvailable($slug);
    }

    /**
     * First segments of the routes outside the accounts (/login, /livewire-…/update…): the list
     * in config can lag behind a newly added route, the router cannot.
     *
     * @return array<int, string>
     */
    private function rootSegments(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->reject(fn (RouteDefinition $route) => in_array('tenant', $route->parameterNames(), true))
            ->map(fn (RouteDefinition $route) => explode('/', trim($route->uri(), '/'))[0])
            ->reject(fn (string $segment) => $segment === '' || str_starts_with($segment, '{'))
            ->unique()
            ->values()
            ->all();
    }
}
