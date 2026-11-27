<?php

namespace App\Http\Controllers;

use App\Services\SearchEngineService;
use Illuminate\Http\Response;

/**
 * The sitemap search engines read. Public rows only — see SearchEngineService.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $xml = view('sitemap', ['urls' => app(SearchEngineService::class)->sitemapUrls()])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
