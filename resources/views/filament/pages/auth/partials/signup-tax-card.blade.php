{{-- The tax card (sign-up tax profile and "Conta criada"): who, the regime and the key codes. Data from SignupQuestions::profileViewData. --}}
<div class="contiu-signup-card" role="img" aria-label="{{ __('Tax card of :name', ['name' => $name]) }}">
    <div class="contiu-signup-card-top">
        <img src="{{ $logo }}" alt="">
        <span>{{ $regime }}</span>
    </div>
    <div class="contiu-signup-card-who">
        <span class="contiu-signup-avatar" aria-hidden="true">{{ $initials }}</span>
        <div>
            <p class="contiu-signup-card-name">{{ $name }}</p>
            @if (filled($role))
                <p class="contiu-signup-card-role">{{ $role }}</p>
            @endif
        </div>
    </div>
    @if ($facts !== [])
        <dl class="contiu-signup-card-facts">
            @foreach ($facts as $fact)
                <div><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>
            @endforeach
        </dl>
    @endif
</div>
