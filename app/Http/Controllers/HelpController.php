<?php

namespace App\Http\Controllers;

use App\Support\HelpArticles;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The public site's help centre: the start page, a theme, the search and each article. */
class HelpController extends Controller
{
    public function index(): View
    {
        return view('site.help');
    }

    public function search(Request $request): View
    {
        $query = trim($request->string('q')->limit(100, '')->toString());

        return view('site.help.search', [
            'query' => $query,
            'results' => HelpArticles::search($query),
        ]);
    }

    public function theme(string $theme): View
    {
        $found = HelpArticles::theme($theme);

        abort_if($found === null, 404);

        return view('site.help.theme', ['theme' => $found]);
    }

    public function article(string $theme, string $article): View
    {
        $found = HelpArticles::find($theme, $article);

        abort_if($found === null, 404);

        return view('site.help.article', [
            'article' => $found,
            'related' => HelpArticles::related($found),
        ]);
    }
}
