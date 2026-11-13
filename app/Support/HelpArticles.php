<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * The help centre of the public site (/ajuda): the questions of the help page (App\Support\SiteFaq)
 * as articles with a page of their own, grouped by theme. An article's longer text is a Blade
 * view under resources/views/site/help/articles, named after its slug; without one, the article
 * is its answer.
 */
final class HelpArticles
{
    /**
     * @return Collection<int, array{slug: string, theme: string, theme_slug: string, question: string, answer: string, url: string, has_text: bool}>
     */
    public static function all(): Collection
    {
        return collect(SiteFaq::for('help'))->map(fn (array $item): array => [
            'slug' => $item['id'],
            'theme' => (string) $item['group'],
            'theme_slug' => Str::slug((string) $item['group']),
            'question' => $item['question'],
            'answer' => $item['answer'],
            'url' => route('site.help.article', [Str::slug((string) $item['group']), $item['id']]),
            'has_text' => View::exists(self::view($item['id'])),
        ]);
    }

    /**
     * The themes in the order of the help page, each with its articles.
     *
     * @return Collection<int, array{slug: string, name: string, url: string, articles: Collection<int, array<string, mixed>>}>
     */
    public static function themes(): Collection
    {
        return self::all()->groupBy('theme_slug')->map(fn (Collection $articles, string $slug): array => [
            'slug' => $slug,
            'name' => $articles->first()['theme'],
            'url' => route('site.help.theme', $slug),
            'articles' => $articles->values(),
        ])->values();
    }

    /** @return array{slug: string, name: string, url: string, articles: Collection<int, array<string, mixed>>}|null */
    public static function theme(string $slug): ?array
    {
        return self::themes()->firstWhere('slug', $slug);
    }

    /** @return array<string, mixed>|null */
    public static function find(string $theme, string $slug): ?array
    {
        return self::all()->first(fn (array $article): bool => $article['theme_slug'] === $theme && $article['slug'] === $slug);
    }

    /**
     * The most asked articles, in the order of «Mais procuradas».
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function popular(): Collection
    {
        $articles = self::all()->keyBy('question');

        return collect(SiteFaq::popular())->map(fn (array $item): ?array => $articles->get($item['question']))->filter()->values();
    }

    /**
     * The articles with every word of the search in the question or the answer, accents and
     * case ignored.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function search(string $query): Collection
    {
        $words = array_filter(explode(' ', self::plain($query)));

        if ($words === []) {
            return collect();
        }

        return self::all()->filter(function (array $article) use ($words): bool {
            $text = self::plain($article['question'].' '.$article['answer']);

            return collect($words)->every(fn (string $word): bool => str_contains($text, $word));
        })->values();
    }

    /**
     * Other articles to read after this one: its theme first, then the most asked.
     *
     * @param  array<string, mixed>  $article
     * @return Collection<int, array<string, mixed>>
     */
    public static function related(array $article, int $count = 3): Collection
    {
        return self::all()->where('theme_slug', $article['theme_slug'])
            ->merge(self::popular())
            ->reject(fn (array $other): bool => $other['slug'] === $article['slug'])
            ->unique('slug')
            ->take($count)
            ->values();
    }

    public static function view(string $slug): string
    {
        return 'site.help.articles.'.$slug;
    }

    private static function plain(string $text): string
    {
        return (string) Str::of($text)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish();
    }
}
