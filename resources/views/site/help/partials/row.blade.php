{{-- An article in a list: its theme (when $withTheme), the question and the start of the answer, the searched words marked. --}}
@php
    $searched = $query ?? '';
    $mark = function (string $text) use ($searched): string {
        $escaped = e($text);
        $words = array_filter(explode(' ', (string) \Illuminate\Support\Str::of($searched)->squish()));

        foreach ($words as $word) {
            $escaped = preg_replace('/('.preg_quote(e($word), '/').')/iu', '<mark>$1</mark>', $escaped);
        }

        return $escaped;
    };
@endphp
<a class="ha-row" href="{{ $article['url'] }}">@if ($withTheme ?? false)<small>{{ $article['theme'] }}</small>@endif<b>{!! $mark($article['question']) !!}</b><span>{!! $mark(\Illuminate\Support\Str::limit($article['answer'], 150)) !!}</span></a>
