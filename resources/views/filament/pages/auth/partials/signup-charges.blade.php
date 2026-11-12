{{--
    Payment step with Contiu's certified accountant (RegisterAccount::chargesData): what is charged
    today, the plan chosen on this screen (in the browser), the accountant every month and the total
    of the first charge.
--}}
<div class="contiu-signup-block">
    <p class="contiu-signup-question">{{ __('What you will pay') }}</p>
    <div class="contiu-signup-charges">
        <div>
            <span>{{ __('Today') }}<small>{{ __('Free days, the accountant included') }}</small></span>
            <b>{{ \App\Support\Money::format(0) }}</b>
        </div>
        @foreach ($plans as $key => $plan)
            <div x-show="$wire.data?.plan === @js($key)" x-cloak>
                <span>{{ __('Plan :plan', ['plan' => mb_strtolower($plan['label'])]) }}<small>{{ __('On :date', ['date' => $chargedOn]) }} · {{ $plan['renews'] }}</small></span>
                <b>{{ \App\Support\Money::format($plan['amount']) }}</b>
            </div>
        @endforeach
        <div>
            <span>{{ __('Certified accountant') }}<small>{{ __('On :date', ['date' => $chargedOn]) }} · {{ __('Then every month') }} · {{ __(':net + VAT', ['net' => \App\Support\Money::format($accountantNet)]) }}</small></span>
            <b>{{ \App\Support\Money::format($accountant) }}</b>
        </div>
        @foreach ($plans as $key => $plan)
            <div class="contiu-signup-charges-total" x-show="$wire.data?.plan === @js($key)" x-cloak>
                <span>{{ __('On :date', ['date' => $chargedOn]) }}</span>
                <b>{{ \App\Support\Money::format($plan['amount'] + $accountant) }}</b>
            </div>
        @endforeach
    </div>
</div>
