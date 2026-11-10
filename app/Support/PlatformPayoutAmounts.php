<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DomainException;
use Money\Currencies\ISOCurrencies;
use Money\Currency;

final readonly class PlatformPayoutAmounts
{
    private function __construct(
        public string $currency,
        public int $originalGross,
        public int $originalCommission,
        public int $grossEur,
        public int $commissionEur,
        public ?string $fxRate,
    ) {}

    /** @param array<string, mixed> $data Amounts in original currency minor units. */
    public static function from(array $data): self
    {
        $currency = $data['currency'] ?? null;
        $scale = self::scale($currency);
        $gross = self::amount($data['original_gross'] ?? null);
        $commission = self::amount($data['original_commission'] ?? null);

        if ($gross <= $commission) {
            throw new DomainException('O valor bruto tem de ser superior à comissão.');
        }

        if ($currency === 'EUR') {
            return new self($currency, $gross, $commission, $gross, $commission, null);
        }

        $rate = is_string($data['fx_rate'] ?? null) ? str_replace(',', '.', trim($data['fx_rate'])) : '';

        if (! preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,12})?\z/', $rate) || BigDecimal::of($rate)->isZero()) {
            throw new DomainException('Indica uma taxa de conversão para EUR superior a zero.');
        }

        try {
            $convert = fn (int $amount): int => BigDecimal::ofUnscaledValue($amount, $scale)
                ->multipliedBy($rate)->withPointMovedRight(2)->toScale(0, RoundingMode::HalfUp)->toInt();

            $grossEur = $convert($gross);
            $commissionEur = $convert($commission);
        } catch (MathException) {
            throw new DomainException('O valor convertido para euros é demasiado elevado.');
        }

        if ($grossEur <= $commissionEur || ($commission > 0 && $commissionEur === 0)) {
            throw new DomainException('A conversão arredonda o líquido ou a comissão para zero.');
        }

        return new self($currency, $gross, $commission, $grossEur, $commissionEur, $rate);
    }

    public function netEur(): int
    {
        return $this->grossEur - $this->commissionEur;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function minorUnitsFromForm(array $data): array
    {
        $scale = self::scale($data['currency'] ?? null);

        return [
            ...$data,
            'original_gross' => self::parse($data['original_gross'] ?? null, $scale),
            'original_commission' => self::parse($data['original_commission'] ?? null, $scale),
        ];
    }

    public static function formatOriginal(int $amount, string $currency): string
    {
        return str_replace('.', ',', (string) BigDecimal::ofUnscaledValue($amount, self::scale($currency))).' '.$currency;
    }

    private static function scale(mixed $code): int
    {
        $currencies = new ISOCurrencies;

        if (! is_string($code) || ! preg_match('/\A[A-Z]{3}\z/', $code) || ! $currencies->contains(new Currency($code))) {
            throw new DomainException('Escolhe uma moeda válida.');
        }

        return $currencies->subunitFor(new Currency($code));
    }

    private static function parse(mixed $value, int $scale): int
    {
        $decimal = is_scalar($value) ? str_replace(',', '.', trim((string) $value)) : '';

        if (! preg_match('/\A[0-9]{1,19}(?:\.[0-9]{1,4})?\z/', $decimal)) {
            throw new DomainException('Indica valores originais válidos.');
        }

        try {
            return BigDecimal::of($decimal)->toScale($scale)->withPointMovedRight($scale)->toInt();
        } catch (MathException) {
            throw new DomainException('Confere o montante e as casas decimais da moeda original.');
        }
    }

    private static function amount(mixed $amount): int
    {
        $integer = is_int($amount) || is_string($amount)
            ? filter_var($amount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;

        if ($integer === false) {
            throw new DomainException('Indica um valor bruto e uma comissão válidos.');
        }

        return $integer;
    }
}
