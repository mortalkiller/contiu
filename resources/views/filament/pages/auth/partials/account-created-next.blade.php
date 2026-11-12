{{-- "Conta criada": the first steps once in, or what to do when the activity is opened (numbered). --}}
<section class="contiu-signup-done-block">
    <h2 class="contiu-signup-question">{{ $next['title'] }}</h2>
    <ol class="contiu-signup-done-next" @if ($next['numbered']) data-numbered @endif>
        @foreach ($next['items'] as [$title, $text])
            <li><b>{{ $title }}</b>{{ $text }}</li>
        @endforeach
    </ol>
</section>
