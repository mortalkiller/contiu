<span
    class="fi-sc-text"
    x-load
    x-load-src="{{ asset('js/contiu-platform-payout-preview.js') }}"
    x-data="contiuPlatformPayoutPreview(@js([
        'amount' => $amount,
        'locale' => $locale,
        'scales' => $scales,
        'difference' => __('Difference from bank deposit: :amount'),
        'overflow' => __('The net payout amount exceeds the bank deposit.'),
    ]))"
    x-text="preview"
    aria-live="polite"
></span>
