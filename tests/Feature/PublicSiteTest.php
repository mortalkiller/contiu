<?php

use App\Filament\Resources\Projects\ProjectResource;
use App\Support\Blog;
use App\Support\HelpArticles;
use App\Support\PanelLocation;
use App\Support\PublicSite;
use Illuminate\Testing\TestResponse;

pest()->group('ui');

/**
 * The page's JSON-LD graph, by @type.
 *
 * @return array<string, array<string, mixed>>
 */
function siteJsonLd(TestResponse $response): array
{
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $match);

    return collect(json_decode($match[1], true, flags: JSON_THROW_ON_ERROR)['@graph'])->keyBy('@type')->all();
}

it('serves each page of the site with its own title, description, canonical address and preview image', function (string $route, string $title, string $type) {
    $response = $this->get(route($route))->assertOk();

    $response
        ->assertSee("<title>{$title}</title>", escape: false)
        ->assertSee('<link rel="canonical" href="'.route($route).'">', escape: false)
        ->assertSee('<meta property="og:title" content="'.e($title).'">', escape: false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', escape: false)
        ->assertSee('favicons/favicon.svg', escape: false);

    preg_match('#<meta property="og:image" content="([^"]+)">#', $response->getContent(), $image);
    expect(public_path(str($image[1])->after(url('/').'/')))->toBeFile();

    expect(siteJsonLd($response))->toHaveKeys(['Organization', 'WebSite', $type]);
})->with([
    'home' => ['site.home', 'Contiu · Dos recibos verdes aos impostos em dia', 'SoftwareApplication'],
    'features' => ['site.features', 'Funcionalidades · Contiu', 'SoftwareApplication'],
    'pricing' => ['site.pricing', 'Preços · Contiu', 'SoftwareApplication'],
    'accountant' => ['site.accountant', 'Contabilista certificado · Contiu', 'Service'],
    'blog' => ['site.blog', 'Blogue · Contiu', 'Blog'],
    'help' => ['site.help', 'Ajuda · Contiu', 'CollectionPage'],
]);

it('keeps the site at the root and the panel at /app, on its own domain when one is set', function () {
    actingAsAdmin();

    $this->get('/')->assertOk()->assertSee('recibos verdes');
    expect(ProjectResource::getUrl())->toStartWith(url('/app/'))
        ->and(PanelLocation::url('login'))->toBe(url('/app/login'));

    config(['contiu.panel_domain' => 'app.contiu.test', 'app.url' => 'https://contiu.test']);

    expect(PanelLocation::path())->toBe('')
        ->and(PanelLocation::url('login'))->toBe('https://app.contiu.test/login')
        ->and(PanelLocation::siteDomain())->toBe('contiu.test');
});

it('shows the prices with VAT everywhere on the site', function () {
    $this->get(route('site.home'))->assertSee('sem preocupações <b>desde 6,30 €/mês</b>', escape: false);

    $this->get(route('site.pricing'))
        ->assertSeeInOrder(['<h1 class="st-h1"', 'O <b>preço</b> que um independente merece.', 'data-billing="monthly"'], escape: false)
        ->assertSeeInOrder(['<b data-price>7 €</b>', '* Valores com IVA incluído.', 'o plano é pago de uma vez: 75,60 €'], escape: false)
        ->assertSeeInOrder(['data-regime="simplified"', '+92,25 €', 'data-regime="organised"', '+246 €'], escape: false)
        ->assertDontSee('+ IVA');

    $this->get(route('site.help'))->assertSee('7 € por mês ou 75,60 € por ano, IVA incluído', escape: false);

    $this->get(route('site.accountant'))->assertSeeInOrder(['Regime simplificado', '92,25 €', 'Contabilidade organizada', '246 €'], escape: false);

    $offers = collect(siteJsonLd($this->get(route('site.pricing')))['SoftwareApplication']['offers']);
    expect($offers->pluck('price')->all())->toBe(['7.00', '75.60'])
        ->and($offers->pluck('priceSpecification.valueAddedTaxIncluded')->unique()->all())->toBe([true]);
});

it('shows the plan on the home page with VAT, monthly first, with or without the accountant', function () {
    expect(PublicSite::pricesWithVat())->toBe([
        'monthly' => ['contiu' => '7 €', 'simplified' => '99,25 €', 'organised' => '253 €'],
        'yearly' => ['contiu' => '6,30 €', 'simplified' => '98,55 €', 'organised' => '252,30 €'],
        'yearly_total' => '75,60 €',
    ]);

    $this->get(route('site.home'))
        ->assertSeeInOrder(['data-billing="monthly"', 'data-billing="yearly"'], escape: false)
        ->assertSeeInOrder(['<b data-price>7 €</b>', '* Valores com IVA incluído.', 'o plano é pago de uma vez: 75,60 €'], escape: false)
        ->assertSeeInOrder(['data-regime="simplified"', '+92,25 €', 'data-regime="organised"', '+246 €'], escape: false)
        ->assertSee('data-only-plan="contab" hidden', escape: false);
});

it('lists every help article on the help centre, by theme, as search engines read it', function () {
    $response = $this->get(route('site.help'));
    $articles = HelpArticles::all();

    $response->assertSeeInOrder(HelpArticles::themes()->pluck('name')->all());

    foreach ($articles as $article) {
        $response->assertSee('href="'.$article['url'].'"', escape: false);
    }

    expect(collect(siteJsonLd($response)['CollectionPage']['hasPart'])->pluck('headline'))->toEqual($articles->pluck('question'));
});

it('opens the most asked articles from the help centre', function () {
    $popular = HelpArticles::popular();

    expect($popular->pluck('question')->all())->toBe([
        'O Contiu é para mim?',
        'Os valores são oficiais?',
        'Posso emitir faturas pelo Contiu?',
        'Quanto custa?',
        'Posso cancelar quando quiser?',
    ]);

    $response = $this->get(route('site.help'));

    foreach ($popular as $article) {
        $response->assertSeeInOrder(['Mais procuradas', 'href="'.$article['url'].'"', 'Perguntas <b>mais frequentes</b>', 'href="'.$article['url'].'"'], escape: false);
    }
});

it('shows a theme of the help centre with its articles, and no theme that does not exist', function () {
    $theme = HelpArticles::theme('impostos');

    $response = $this->get(route('site.help.theme', 'impostos'))->assertOk()
        ->assertSee('<title>Impostos · Ajuda Contiu</title>', escape: false)
        ->assertSee('<link rel="canonical" href="'.route('site.help.theme', 'impostos').'">', escape: false);

    $response->assertSeeInOrder($theme['articles']->pluck('question')->all());

    $this->get(route('site.help.theme', 'nao-existe'))->assertNotFound();
});

it('shows a help article with its answer, its longer text when it has one, and related articles', function () {
    $article = HelpArticles::find('faturacao', 'posso-emitir-faturas-pelo-contiu');

    $response = $this->get($article['url'])->assertOk()
        ->assertSee('<title>Posso emitir faturas pelo Contiu? · Ajuda Contiu</title>', escape: false)
        ->assertSeeInOrder(['Faturação', 'Posso emitir faturas pelo Contiu?', $article['answer'], 'Ligar o InvoiceXpress', 'Artigos <b>relacionados</b>', 'E se eu não tiver programa de faturação?'], escape: false);

    $jsonLd = siteJsonLd($response);
    expect($jsonLd['Article']['headline'])->toBe('Posso emitir faturas pelo Contiu?')
        ->and(collect($jsonLd['BreadcrumbList']['itemListElement'])->pluck('name')->all())->toBe(['Início', 'Ajuda', 'Faturação', 'Posso emitir faturas pelo Contiu?']);

    $this->get(HelpArticles::find('impostos', 'os-valores-sao-oficiais')['url'])->assertOk()
        ->assertSee('O valor oficial é sempre o da Autoridade Tributária')
        ->assertDontSee('class="st-prose"', escape: false);

    $this->get(route('site.help.article', ['faturacao', 'nao-existe']))->assertNotFound();
    $this->get(route('site.help.article', ['impostos', 'posso-emitir-faturas-pelo-contiu']))->assertNotFound();
});

it('searches the help articles with every word, accents and case ignored, out of search engines', function () {
    $this->get(route('site.help.search', ['q' => 'FATURACAO programa']))->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false)
        ->assertSee('1 artigo')
        ->assertSee('E se eu não tiver <mark>programa</mark> de faturação?', escape: false)
        ->assertDontSee('Posso cancelar quando quiser?</b>', escape: false);

    $this->get(route('site.help.search', ['q' => '<script>alert(1)</script>']))->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('Não encontrámos nenhum artigo');
});

it('lists the help themes and articles in the sitemap', function () {
    $response = $this->get(route('site.sitemap'))->assertOk();

    foreach (HelpArticles::themes() as $theme) {
        $response->assertSee('<loc>'.$theme['url'].'</loc>', escape: false);
    }

    $response->assertSee('<loc>'.HelpArticles::find('faturacao', 'posso-emitir-faturas-pelo-contiu')['url'].'</loc>', escape: false);
});

it('points the help page to the help email, without the free trial band', function () {
    $this->get(route('site.help'))
        ->assertSee('href="mailto:ajuda@contiu.pt"', escape: false)
        ->assertDontSee('st-band', escape: false);
});

it('links the sign-in and the free trial to the panel', function () {
    config(['contiu.registration_open' => false]);

    $this->get(route('site.home'))
        ->assertSee('href="'.url('/app/login').'"', escape: false)
        ->assertDontSee('/register', escape: false);
});

it('lists the blog by category or search, keeping those lists out of search engines', function () {
    $this->get(route('site.blog'))
        ->assertSee('Em destaque')
        ->assertSee('Recibos verdes e um emprego ao mesmo tempo');

    $this->get(route('site.blog', ['categoria' => 'irs']))
        ->assertSee('IRS Jovem para trabalhadores independentes')
        ->assertDontSee('Declaração trimestral: o que entregar e quando')
        ->assertDontSee('Em destaque')
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false)
        ->assertSee('<link rel="canonical" href="'.route('site.blog').'">', escape: false);

    $this->get(route('site.blog', ['q' => 'fatura']))
        ->assertSee('Fatura, fatura-recibo ou recibo: qual passar?')
        ->assertDontSee('IRS Jovem para trabalhadores independentes');
});

it('opens an article with its text, and none for a post still to be written', function () {
    $response = $this->get(route('site.blog.post', 'isencao-do-artigo-53'))
        ->assertOk()
        ->assertSee('O que acontece quando passas os 15 000 €')
        ->assertSee('<meta property="og:type" content="article">', escape: false)
        ->assertSee('<meta property="article:published_time" content="2026-11-12">', escape: false);

    expect(siteJsonLd($response)['BlogPosting'])
        ->headline->toBe('Isenção do artigo 53.º: até onde vai')
        ->datePublished->toBe('2026-11-12')
        ->and(siteJsonLd($response)['BreadcrumbList']['itemListElement'])->toHaveCount(3);

    expect(Blog::hasText('irs-jovem-independentes'))->toBeFalse();
    $this->get(route('site.blog.post', 'irs-jovem-independentes'))->assertNotFound();
    $this->get(route('site.blog.post', 'nao-existe'))->assertNotFound();
});

it('maps the site for search engines and keeps them out of the panel', function () {
    $this->get(route('site.sitemap'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<loc>'.route('site.pricing').'</loc>', escape: false)
        ->assertSee('<loc>'.route('site.blog.post', 'isencao-do-artigo-53').'</loc>', escape: false)
        ->assertDontSee('irs-jovem-independentes');

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /app/')
        ->assertSee('Sitemap: '.route('site.sitemap'));
});
