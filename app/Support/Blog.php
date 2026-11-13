<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

/**
 * The public site's blog: the articles and their categories. An article's text is a Blade view
 * under resources/views/site/blog/posts, named after its slug; a post without one is listed but
 * does not open yet.
 */
final class Blog
{
    /** @var array<string, string> Category slug => name, in the order of the category bar. */
    public const CATEGORIES = [
        'irs' => 'IRS',
        'iva' => 'IVA',
        'seguranca-social' => 'Segurança Social',
        'faturacao' => 'Faturação',
        'comecar-a-atividade' => 'Começar a atividade',
    ];

    /**
     * The articles, newest first; the featured one opens the blog page.
     *
     * @var list<array{slug: string, category: string, title: string, excerpt: string, minutes: int, image: string, published_on?: string, featured?: bool}>
     */
    private const POSTS = [
        [
            'slug' => 'isencao-do-artigo-53',
            'category' => 'iva',
            'title' => 'Isenção do artigo 53.º: até onde vai',
            'excerpt' => 'Faturas abaixo dos 15 000 € por ano? Explicamos quando podes não cobrar IVA, o que acontece se passares o limite e porque é que os 18 750 € contam.',
            'minutes' => 6,
            'image' => 'blogue-2',
            'published_on' => '2026-11-12',
            'featured' => true,
        ],
        ['slug' => 'quanto-por-de-parte-para-o-irs', 'category' => 'irs', 'title' => 'Quanto pôr de parte para o IRS a recibos verdes', 'excerpt' => 'O coeficiente, a retenção na fonte de 23% e o acerto do ano seguinte.', 'minutes' => 7, 'image' => 'blogue-1'],
        ['slug' => 'seguranca-social-no-primeiro-ano', 'category' => 'seguranca-social', 'title' => 'Segurança Social no primeiro ano de atividade', 'excerpt' => 'Os doze meses de isenção e a primeira declaração trimestral.', 'minutes' => 5, 'image' => 'blogue-3'],
        ['slug' => 'declaracao-trimestral', 'category' => 'seguranca-social', 'title' => 'Declaração trimestral: o que entregar e quando', 'excerpt' => 'Os quatro prazos do ano e como se calcula a contribuição.', 'minutes' => 6, 'image' => 'blogue-4'],
        ['slug' => 'irs-jovem-independentes', 'category' => 'irs', 'title' => 'IRS Jovem para trabalhadores independentes', 'excerpt' => 'Quem tem direito, durante quantos anos e com que limite.', 'minutes' => 8, 'image' => 'blogue-5'],
        ['slug' => 'abrir-atividade-nas-financas', 'category' => 'comecar-a-atividade', 'title' => 'Abrir atividade nas Finanças, passo a passo', 'excerpt' => 'O código da atividade, o regime de IVA e o regime de tributação.', 'minutes' => 9, 'image' => 'blogue-6'],
        ['slug' => 'fatura-fatura-recibo-ou-recibo', 'category' => 'faturacao', 'title' => 'Fatura, fatura-recibo ou recibo: qual passar?', 'excerpt' => 'O documento certo para cada situação, com exemplos.', 'minutes' => 5, 'image' => 'blogue-7'],
        ['slug' => 'despesas-no-regime-simplificado', 'category' => 'irs', 'title' => 'Que despesas contam no regime simplificado', 'excerpt' => 'O que justifica os 15% e onde o confirmar no e-fatura.', 'minutes' => 7, 'image' => 'blogue-8'],
        ['slug' => 'recibos-verdes-e-emprego', 'category' => 'comecar-a-atividade', 'title' => 'Recibos verdes e um emprego ao mesmo tempo', 'excerpt' => 'O que muda no IRS e na Segurança Social quando acumulas.', 'minutes' => 6, 'image' => 'blogue-9'],
    ];

    /** @return Collection<int, array<string, mixed>> */
    public static function posts(): Collection
    {
        return collect(self::POSTS)->map(fn (array $post): array => [
            ...$post,
            'featured' => $post['featured'] ?? false,
            'published_on' => $post['published_on'] ?? null,
            'published_label' => isset($post['published_on']) ? Carbon::parse($post['published_on'])->locale('pt_PT')->translatedFormat('j \\d\\e F \\d\\e Y') : null,
            'category_name' => self::CATEGORIES[$post['category']],
            'url' => self::hasText($post['slug']) ? route('site.blog.post', $post['slug']) : null,
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        return self::posts()->firstWhere('slug', $slug);
    }

    /** Whether the article has its text written (a view of its own). */
    public static function hasText(string $slug): bool
    {
        return View::exists(self::view($slug));
    }

    public static function view(string $slug): string
    {
        return 'site.blog.posts.'.$slug;
    }
}
