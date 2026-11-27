<?php

namespace App\Http\Controllers;

use App\Services\SearchEngineService;
use Illuminate\Http\Response;

/**
 * robots.txt, written by the app rather than kept in public/, so the sitemap
 * line carries the full domain. What it says is SearchEngineService's call.
 */
class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        return response(app(SearchEngineService::class)->robots(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
