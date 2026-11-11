<?php

namespace App\Support;

use App\Models\Account;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressIssueSettings
{
    public const string Key = 'invoice_xpress_issue';

    public static function stored(): array
    {
        $value = Setting::query()->where('key', self::Key)->value('value');

        return is_array($value) ? $value : [];
    }

    public static function hasPendingAttempts(): bool
    {
        return InvoiceXpressIssueAttempt::query()
            ->whereIn('state', InvoiceXpressIssueAttempt::Recoverable)
            ->exists();
    }

    public function save(string $approvedItems, string $taxName, string $sequenceId, bool $draftOnly = false): void
    {
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

        if ($taxName === '' || ! mb_check_encoding($taxName) || mb_strlen($taxName) > 255 || preg_match('/[\x00-\x1f\x7f]/', $taxName)) {
            $errors['taxName'] = __('Enter the exact VAT name in InvoiceXpress.');
        }

        if ($series === null) {
            $errors['sequenceId'] = __('Enter the ID of the invoice series registered with the Portuguese Tax Authority.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($itemIds, $taxName, $series, $draftOnly): void {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
            $config = InvoiceXpressConfig::current();

            if ($config === null) {
                throw ValidationException::withMessages(['settings' => __('First set up the connection to InvoiceXpress.')]);
            }

            abort_if($draftOnly && ! InvoiceXpressClient::sandboxDraftsAllowed($config->subdomain), 403);

            $value = [
                'provider' => 'invoicexpress',
                'user_id' => (int) auth()->id(),
                'subdomain' => $config->subdomain,
                'item_ids' => array_values(array_unique($itemIds)),
                'tax_name' => $taxName,
                'sequence_id' => $series,
                ...($draftOnly ? ['draft_only' => true] : []),
            ];

            if (self::stored() === $value) {
                return;
            }

            if (self::hasPendingAttempts()) {
                throw ValidationException::withMessages(['settings' => __('Resume any pending attempts before changing the issuance settings.')]);
            }

            Setting::query()->updateOrCreate(['key' => self::Key], ['value' => $value]);
        });
    }
}
