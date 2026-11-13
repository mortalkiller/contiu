<?php

namespace App\Support;

use App\Filament\Pages\AccountSubscription;

/**
 * What search engines and link previews read of each page of the public site: the title and
 * description, the canonical address, the Open Graph image (public/site/og) and the page's
 * structured data (JSON-LD, schema.org), for resources/views/site/partials/seo.blade.php.
 */
final class SiteSeo
{
    /**
     * @param  array<string, mixed>|null  $post  A blog article (App\Support\Blog), for its own page.
     * @return array{title: string, description: string, canonical: string, image: string, image_alt: string, type: string, robots: string, published: ?string, section: ?string, json_ld: array<string, mixed>}
     */
    public static function for(string $page, ?array $post = null): array
    {
        $meta = $post !== null ? self::postMeta($post) : self::pageMeta($page);
        $canonical = $post !== null ? (string) $post['url'] : route(self::routeName($page));
        $searching = $page === 'blog' && (filled(request()->query('q')) || filled(request()->query('categoria')));

        return [
            ...$meta,
            'canonical' => $canonical,
            'robots' => $searching ? 'noindex, follow' : 'index, follow, max-image-preview:large',
            'published' => $post['published_on'] ?? null,
            'section' => $post['category_name'] ?? null,
            'json_ld' => self::jsonLd($page, $meta, $canonical, $post),
        ];
    }

    /** @return array{title: string, description: string, image: string, image_alt: string, type: string} */
    private static function pageMeta(string $page): array
    {
        $trialDays = (int) config('contiu.trial_days');
        $prices = PublicSite::planPrices();
        $accountant = PublicSite::accountantPrice('simplified');

        [$title, $description] = match ($page) {
            'home' => ['Contiu · Dos recibos verdes aos impostos em dia', 'O Contiu faz as contas ao teu IRS, IVA e Segurança Social e diz-te quanto pôr de parte, desde '.PublicSite::lowestMonthlyPrice()."/mês. Experimenta {$trialDays} dias grátis."],
            'features' => ['Funcionalidades · Contiu', 'Impostos estimados mês a mês, calendário fiscal, faturas pelo InvoiceXpress, despesas, clientes, avenças e o fecho do mês, num só sítio.'],
            'pricing' => ['Preços · Contiu', "Um plano só: {$prices['monthly']['with_vat']} por mês ou {$prices['yearly']['with_vat']} por ano, IVA incluído, com {$trialDays} dias grátis. Contabilista certificado opcional desde {$accountant} por mês."],
            'accountant' => ['Contabilista certificado · Contiu', "Junta um contabilista certificado à tua conta do Contiu: entrega o IVA, a Segurança Social e o IRS, desde {$accountant} por mês com IVA."],
            'blog' => ['Blogue · Contiu', 'Impostos explicados a quem trabalha por conta própria: o IRS, o IVA e a Segurança Social em português claro.'],
            'help' => ['Ajuda · Contiu', 'As respostas às perguntas mais comuns sobre o Contiu, os impostos, a faturação e os pagamentos.'],
        };

        return [
            'title' => $title,
            'description' => $description,
            'image' => asset("site/og/{$page}.png"),
            'image_alt' => $title,
            'type' => 'website',
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array{title: string, description: string, image: string, image_alt: string, type: string}
     */
    private static function postMeta(array $post): array
    {
        return [
            'title' => $post['title'].' · Blogue Contiu',
            'description' => $post['excerpt'],
            // An article without an image of its own yet shares the blog's.
            'image' => asset(is_file(public_path("site/og/blog/{$post['slug']}.png")) ? "site/og/blog/{$post['slug']}.png" : 'site/og/blog.png'),
            'image_alt' => $post['title'],
            'type' => 'article',
        ];
    }

    private static function routeName(string $page): string
    {
        return 'site.'.$page;
    }

    /**
     * One @graph per page: who publishes the site (Organization, WebSite), the page itself with
     * its breadcrumb, and what the page is about (the app and its prices, the accountant service,
     * the questions, the blog or the article).
     *
     * @param  array{title: string, description: string, image: string, image_alt: string, type: string}  $meta
     * @param  array<string, mixed>|null  $post
     * @return array<string, mixed>
     */
    private static function jsonLd(string $page, array $meta, string $canonical, ?array $post): array
    {
        $home = route('site.home');
        $company = config('contiu.company');
        $organization = ['@id' => $home.'#organization'];
        $website = ['@id' => $home.'#website'];
        $application = ['@id' => $home.'#software'];

        $graph = [
            [
                '@type' => 'Organization',
                '@id' => $organization['@id'],
                'name' => 'Contiu',
                'url' => $home,
                'logo' => asset('favicons/android-chrome-512x512.png'),
                'email' => $company['email'],
                'legalName' => $company['name'],
                'vatID' => 'PT'.$company['vat_number'],
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => $company['address'], 'addressCountry' => 'PT'],
            ],
            [
                '@type' => 'WebSite',
                '@id' => $website['@id'],
                'name' => 'Contiu',
                'url' => $home,
                'inLanguage' => 'pt-PT',
                'publisher' => $organization,
            ],
            array_filter([
                '@type' => match ($page) {
                    'blog' => 'CollectionPage',
                    'help' => 'FAQPage',
                    default => 'WebPage',
                },
                '@id' => $canonical.'#webpage',
                'url' => $canonical,
                'name' => $meta['title'],
                'description' => $meta['description'],
                'inLanguage' => 'pt-PT',
                'isPartOf' => $website,
                'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => $meta['image'], 'width' => 1200, 'height' => 630],
                'breadcrumb' => $page === 'home' ? null : ['@id' => $canonical.'#breadcrumb'],
                'about' => in_array($page, ['home', 'features', 'pricing'], true) ? $application : null,
                'mainEntity' => $page === 'help' ? self::questions('help') : null,
            ]),
        ];

        if ($page !== 'home') {
            $graph[] = self::breadcrumb($canonical, $page, $post);
        }

        if (in_array($page, ['home', 'features', 'pricing'], true)) {
            $graph[] = self::application($application['@id'], $organization);
        }

        if ($page === 'accountant') {
            $graph[] = self::accountantService($canonical, $organization);
        }

        if (in_array($page, ['home', 'pricing', 'accountant'], true)) {
            $graph[] = ['@type' => 'FAQPage', '@id' => $canonical.'#faq', 'isPartOf' => ['@id' => $canonical.'#webpage'], 'mainEntity' => self::questions($page)];
        }

        if ($page === 'blog' && $post === null) {
            $graph[] = [
                '@type' => 'Blog',
                '@id' => route('site.blog').'#blog',
                'name' => 'Blogue do Contiu',
                'url' => route('site.blog'),
                'inLanguage' => 'pt-PT',
                'publisher' => $organization,
                'blogPost' => Blog::posts()->whereNotNull('url')->map(fn (array $item): array => ['@id' => $item['url'].'#article'])->values()->all(),
            ];
        }

        if ($post !== null) {
            $graph[] = array_filter([
                '@type' => 'BlogPosting',
                '@id' => $canonical.'#article',
                'mainEntityOfPage' => ['@id' => $canonical.'#webpage'],
                'headline' => $post['title'],
                'description' => $post['excerpt'],
                'image' => $meta['image'],
                'datePublished' => $post['published_on'],
                'dateModified' => $post['published_on'],
                'articleSection' => $post['category_name'],
                'inLanguage' => 'pt-PT',
                'timeRequired' => 'PT'.$post['minutes'].'M',
                'author' => ['@type' => 'Organization', 'name' => 'Equipa Contiu', 'url' => $home],
                'publisher' => $organization,
                'isPartOf' => ['@id' => route('site.blog').'#blog'],
            ]);
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * @param  array<string, mixed>|null  $post
     * @return array<string, mixed>
     */
    private static function breadcrumb(string $canonical, string $page, ?array $post): array
    {
        $names = ['features' => 'Funcionalidades', 'pricing' => 'Preços', 'accountant' => 'Contabilista certificado', 'blog' => 'Blogue', 'help' => 'Ajuda'];
        $trail = [['Início', route('site.home')], [$names[$page], route(self::routeName($page))]];

        if ($post !== null) {
            $trail[] = [$post['title'], $canonical];
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical.'#breadcrumb',
            'itemListElement' => array_map(fn (array $crumb, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb[0],
                'item' => $crumb[1],
            ], $trail, array_keys($trail)),
        ];
    }

    /**
     * The app with both plans, VAT included, as the site shows them.
     *
     * @param  array<string, string>  $organization
     * @return array<string, mixed>
     */
    private static function application(string $id, array $organization): array
    {
        $offer = fn (string $plan, string $name, string $duration, string $unit): array => [
            '@type' => 'Offer',
            'name' => $name,
            'price' => number_format(AccountSubscription::priceWithVat($plan) / 100, 2, '.', ''),
            'priceCurrency' => 'EUR',
            'url' => route('site.pricing'),
            'eligibleRegion' => 'PT',
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification',
                'price' => number_format(AccountSubscription::priceWithVat($plan) / 100, 2, '.', ''),
                'priceCurrency' => 'EUR',
                'valueAddedTaxIncluded' => true,
                'billingDuration' => $duration,
                'unitCode' => $unit,
            ],
        ];

        return [
            '@type' => 'SoftwareApplication',
            '@id' => $id,
            'name' => 'Contiu',
            'applicationCategory' => 'FinanceApplication',
            'applicationSubCategory' => 'Contabilidade e impostos para trabalhadores independentes',
            'operatingSystem' => 'Web',
            'url' => route('site.home'),
            'description' => 'O Contiu faz as contas ao IRS, ao IVA e à Segurança Social de quem trabalha por conta própria em Portugal.',
            'inLanguage' => 'pt-PT',
            'publisher' => $organization,
            'offers' => [
                $offer('monthly', 'Plano Contiu mensal', 'P1M', 'MON'),
                $offer('yearly', 'Plano Contiu anual', 'P1Y', 'ANN'),
            ],
        ];
    }

    /**
     * The certified-accountant service: provided by an independent accountant, Contiu only
     * brings them together (broker). Prices with VAT, as the page shows them.
     *
     * @param  array<string, string>  $organization
     * @return array<string, mixed>
     */
    private static function accountantService(string $canonical, array $organization): array
    {
        $offer = fn (string $plan, string $name): array => [
            '@type' => 'Offer',
            'name' => $name,
            'price' => number_format(AccountantService::priceWithVat($plan) / 100, 2, '.', ''),
            'priceCurrency' => 'EUR',
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification',
                'price' => number_format(AccountantService::priceWithVat($plan) / 100, 2, '.', ''),
                'priceCurrency' => 'EUR',
                'valueAddedTaxIncluded' => true,
                'billingDuration' => 'P1M',
                'unitCode' => 'MON',
            ],
        ];

        return [
            '@type' => 'Service',
            '@id' => $canonical.'#service',
            'name' => 'Contabilista certificado',
            'serviceType' => 'Contabilidade certificada',
            'description' => 'Um contabilista certificado independente entrega as declarações do IVA, da Segurança Social e do IRS, a trabalhar sobre os dados da conta do Contiu.',
            'areaServed' => ['@type' => 'Country', 'name' => 'Portugal'],
            'broker' => $organization,
            'url' => $canonical,
            'offers' => [
                $offer('simplified', 'Regime simplificado'),
                $offer('organised', 'Contabilidade organizada'),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function questions(string $page): array
    {
        return array_map(fn (array $item): array => [
            '@type' => 'Question',
            'name' => $item['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
        ], SiteFaq::for($page));
    }
}
