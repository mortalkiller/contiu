{{-- Sign-up screen heading: "Passo 2 de 6", the question and a line about it. --}}
@unless ($hidden)
    <div class="contiu-signup-heading">
        <p class="contiu-signup-eyebrow">{{ $eyebrow }}</p>
        <h1 class="contiu-signup-title">{{ $title }}</h1>
        @if ($lede)
            <p class="contiu-signup-lede">{{ $lede }}</p>
        @endif
    </div>
@endunless
