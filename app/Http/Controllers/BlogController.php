<?php

namespace App\Http\Controllers;

use App\Support\Blog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The public site's blog: the list (by category, or a search) and each article. */
class BlogController extends Controller
{
    public function index(Request $request): View
    {
        $category = array_key_exists($request->string('categoria')->toString(), Blog::CATEGORIES)
            ? $request->string('categoria')->toString()
            : null;
        $search = trim($request->string('q')->toString());

        $posts = Blog::posts()
            ->when($category, fn ($posts) => $posts->where('category', $category))
            ->when($search !== '', fn ($posts) => $posts->filter(fn (array $post): bool => str($post['title'].' '.$post['excerpt'])->lower()->ascii()->contains(str($search)->lower()->ascii())));

        $featured = $category === null && $search === '' ? Blog::posts()->firstWhere('featured', true) : null;

        return view('site.blog', [
            'featured' => $featured,
            'posts' => $posts->reject(fn (array $post): bool => $post['slug'] === ($featured['slug'] ?? null))->values(),
            'category' => $category,
            'search' => $search,
        ]);
    }

    public function show(string $slug): View
    {
        $post = Blog::find($slug);

        abort_unless($post !== null && Blog::hasText($slug), 404);

        return view(Blog::view($slug), [
            'post' => $post,
            'seoPost' => $post,
            'related' => Blog::posts()->where('slug', '!=', $slug)->take(3)->values(),
        ]);
    }
}
