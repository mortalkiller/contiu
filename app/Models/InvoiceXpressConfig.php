<?php

namespace App\Models;

use App\Exceptions\AccountIsReadOnly;
use App\Models\Concerns\BelongsToAccount;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class InvoiceXpressConfig extends Model
{
    use BelongsToAccount;

    public const string SUBDOMAIN_PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i';

    protected $fillable = ['subdomain', 'api_key'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }

    public static function current(): ?self
    {
        $request = request();

        if ($request->route() === null || ! $request->isMethod('GET')) {
            return static::query()->first();
        }

        $key = self::class.':'.CurrentAccount::id();

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, static::query()->first());
        }

        return $request->attributes->get($key);
    }

    public static function store(string $subdomain, #[SensitiveParameter] string $apiKey): self
    {
        $accountId = CurrentAccount::id();

        if (CurrentAccount::readOnly() || AccountantAccess::isReviewOnly()) {
            throw new AccountIsReadOnly;
        }

        $subdomain = strtolower(trim($subdomain));

        if (! preg_match(self::SUBDOMAIN_PATTERN, $subdomain)) {
            throw ValidationException::withMessages(['subdomain' => __('Enter only the subdomain of the InvoiceXpress account.')]);
        }

        if (trim($apiKey) === '' || strlen($apiKey) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $apiKey)) {
            throw ValidationException::withMessages(['api_key' => __('Enter a valid API key.')]);
        }

        return DB::transaction(function () use ($accountId, $subdomain, $apiKey): self {
            Account::query()->lockForUpdate()->findOrFail($accountId);

            return static::query()->updateOrCreate(['account_id' => $accountId], ['subdomain' => $subdomain, 'api_key' => $apiKey]);
        });
    }
}
