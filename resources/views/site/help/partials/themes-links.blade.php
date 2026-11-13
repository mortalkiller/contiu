<p class="tp-links">Temas: @foreach (\App\Support\HelpArticles::themes() as $linkTheme)<a href="{{ $linkTheme['url'] }}">{{ $linkTheme['name'] }}</a>@endforeach</p>
