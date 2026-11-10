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

    public function save(string $approvedItems, string $taxName, string $sequenceId): void
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
            $errors['approvedItems'] = 'Indica entre 1 e 25 IDs válidos, separados por vírgula ou por linha.';
        }

        if ($taxName === '' || ! mb_check_encoding($taxName) || mb_strlen($taxName) > 255 || preg_match('/[\x00-\x1f\x7f]/', $taxName)) {
            $errors['taxName'] = 'Indica o nome exato do IVA no InvoiceXpress.';
        }

        if ($series === null) {
            $errors['sequenceId'] = 'Indica o ID da série de faturas registada na AT.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($itemIds, $taxName, $series): void {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
            $config = InvoiceXpressConfig::current();

            if ($config === null) {
                throw ValidationException::withMessages(['settings' => 'Configura primeiro a ligação ao InvoiceXpress.']);
            }

            $value = [
                'provider' => 'invoicexpress',
                'user_id' => (int) auth()->id(),
                'subdomain' => $config->subdomain,
                'item_ids' => array_values(array_unique($itemIds)),
                'tax_name' => $taxName,
                'sequence_id' => $series,
            ];

            if (self::stored() === $value) {
                return;
            }

            if (self::hasPendingAttempts()) {
                throw ValidationException::withMessages(['settings' => 'Retoma as tentativas pendentes antes de alterar a configuração de emissão.']);
            }

            Setting::query()->updateOrCreate(['key' => self::Key], ['value' => $value]);
        });
    }
}
