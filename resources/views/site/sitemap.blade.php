{{-- The public site's pages, the blog articles that have their text (App\Support\Blog) and the help centre's themes and articles (App\Support\HelpArticles). --}}
{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach (['site.home' => '1.0', 'site.features' => '0.8', 'site.pricing' => '0.8', 'site.accountant' => '0.7', 'site.blog' => '0.6', 'site.help' => '0.5', 'legal.terms' => '0.2', 'legal.privacy' => '0.2'] as $route => $priority)
    <url><loc>{{ route($route) }}</loc><priority>{{ $priority }}</priority></url>
@endforeach
@foreach (\App\Support\Blog::posts()->whereNotNull('url') as $post)
    <url><loc>{{ $post['url'] }}</loc>@if ($post['published_on'])<lastmod>{{ $post['published_on'] }}</lastmod>@endif<priority>0.6</priority></url>
@endforeach
@foreach (\App\Support\HelpArticles::themes() as $theme)
    <url><loc>{{ $theme['url'] }}</loc><priority>0.4</priority></url>
@foreach ($theme['articles'] as $article)
    <url><loc>{{ $article['url'] }}</loc><priority>0.4</priority></url>
@endforeach
@endforeach
</urlset>
