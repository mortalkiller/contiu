<?php

namespace App\Support;

use App\Enums\PortugueseVatRegion;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressIssueSettings
{
    public const string Key = 'invoice_xpress_issue';

    public function __construct(private readonly InvoiceXpressCatalog $catalog) {}

    public static function stored(): array
    {
        $value = Setting::query()->where('key', self::Key)->value('value');

        return is_array($value) ? $value : [];
    }

    public static function regionalTaxName(PortugueseVatRegion $region, string $category): ?string
    {
        if ($region->basisPoints($category) === null) {
            return null;
        }

        $name = self::regionalTaxNames()[$region->value][$category] ?? null;

        return self::validTaxName($name) ? $name : null;
    }

    /** @return array<string, array<string, string>> */
    public static function regionalTaxNames(): array
    {
        if (CurrentAccount::get() === null || ($config = InvoiceXpressConfig::current()) === null) {
            return [];
        }

        $settings = self::stored();
        $mappings = $settings['regional_tax_names'] ?? null;

        return self::matchesConnection($settings, $config) && is_array($mappings) ? $mappings : [];
    }

    public static function hasPendingAttempts(): bool
    {
        return InvoiceXpressIssueAttempt::query()
            ->whereIn('state', InvoiceXpressIssueAttempt::Recoverable)
            ->exists();
    }

    public function save(
        string $approvedItems,
        string $taxName,
        string $sequenceId,
        bool $draftOnly = false,
        ?array $regionalTaxNames = null,
        ?string $expectedSubdomain = null,
    ): void {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        $ids = strlen($approvedItems) <= 500
            ? preg_split('/[\s,]+/', $approvedItems, flags: PREG_SPLIT_NO_EMPTY)
            : [];
        $itemIds = array_map(InvoiceXpressIssueTerms::positiveId(...), $ids);
        $series = InvoiceXpressIssueTerms::positiveId($sequenceId);
        $taxName = trim($taxName);
        $errors = [];

        if ($itemIds === [] || count($itemIds) > 25 || in_array(null, $itemIds, true)) {
            $errors['approvedItems'] = __('Enter between 1 and 25 valid IDs, separated by commas or line breaks.');
        }

        if (! self::validTaxName($taxName)) {
            $errors['taxName'] = __('Enter the exact VAT name in InvoiceXpress.');
        }

        if ($series === null) {
            $errors['sequenceId'] = __('Enter the ID of the invoice series registered with the Portuguese Tax Authority.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $regionalTaxNames = $regionalTaxNames === null ? null : $this->normalizeRegionalTaxNames($regionalTaxNames);

        DB::transaction(function () use ($itemIds, $taxName, $series, $draftOnly, $regionalTaxNames, $expectedSubdomain): void {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
            $config = InvoiceXpressConfig::current();

            if ($config === null) {
                throw ValidationException::withMessages(['settings' => __('First set up the connection to InvoiceXpress.')]);
            }

            if ($expectedSubdomain !== null && $config->subdomain !== $expectedSubdomain) {
                throw ValidationException::withMessages(['settings' => __('The InvoiceXpress account has changed.')]);
            }

            abort_if($draftOnly && ! InvoiceXpressClient::sandboxDraftsAllowed($config->subdomain), 403);

            $stored = self::stored();
            $currentMappings = self::matchesConnection($stored, $config) ? ($stored['regional_tax_names'] ?? []) : [];
            $mappings = $regionalTaxNames ?? $currentMappings;

            $value = [
                'provider' => 'invoicexpress',
                'user_id' => (int) auth()->id(),
                'subdomain' => $config->subdomain,
                'item_ids' => array_values(array_unique($itemIds)),
                'tax_name' => $taxName,
                'sequence_id' => $series,
                ...($draftOnly ? ['draft_only' => true] : []),
                ...($mappings === [] ? [] : ['regional_tax_names' => $mappings]),
            ];

            if ($stored === $value) {
                return;
            }

            if (self::hasPendingAttempts()) {
                throw ValidationException::withMessages(['settings' => __('Resume any pending attempts before changing the issuance settings.')]);
            }

            if ($mappings !== [] && $mappings !== $currentMappings) {
                $this->validateRegionalTaxNames($mappings, $config->subdomain);
            }

            Setting::query()->updateOrCreate(['key' => self::Key], ['value' => $value]);
        });
    }

    private static function matchesConnection(array $settings, InvoiceXpressConfig $config): bool
    {
        return ($settings['provider'] ?? null) === 'invoicexpress' && ($settings['subdomain'] ?? null) === $config->subdomain;
    }

    private static function validTaxName(mixed $name): bool
    {
        return is_string($name) && $name !== '' && trim($name) === $name
            && mb_check_encoding($name) && mb_strlen($name) <= 255 && ! preg_match('/[\x00-\x1f\x7f]/', $name);
    }

    /** @return array<string, array<string, string>> */
    private function normalizeRegionalTaxNames(array $input): array
    {
        $normalized = [];
        $errors = [];

        foreach ($input as $code => $categories) {
            $region = is_string($code) ? PortugueseVatRegion::tryFrom($code) : null;
            if ($region === null || ! is_array($categories)) {
                $errors['regionalTaxNames'] = __('Select an InvoiceXpress VAT rate for the corresponding region and category.');

                continue;
            }

            foreach ($categories as $category => $name) {
                if (! is_string($category) || $region->basisPoints($category) === null) {
                    $errors['regionalTaxNames'] = __('Select an InvoiceXpress VAT rate for the corresponding region and category.');
                } elseif (self::validTaxName($name)) {
                    $normalized[$code][$category] = $name;
                } elseif ($name !== '' && $name !== null) {
                    $errors["regionalTaxNames.$code.$category"] = __('Enter the exact VAT name in InvoiceXpress.');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /** @param array<string, array<string, string>> $mappings */
    private function validateRegionalTaxNames(array $mappings, string $subdomain): void
    {
        try {
            $choices = $this->catalog->regionalTaxes($subdomain);
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['regionalTaxNames' => $exception->getMessage()]);
        }

        $errors = [];
        foreach ($mappings as $region => $categories) {
            foreach ($categories as $category => $name) {
                if (! in_array($name, array_column($choices[$region][$category] ?? [], 'name'), true)) {
                    $errors["regionalTaxNames.$region.$category"] = __('Select an InvoiceXpress VAT rate for the corresponding region and category.');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
