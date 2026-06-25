<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Pick the best Accept-Language match; first entry ('nl') is the fallback.
        $locale = $request->getPreferredLanguage(['nl', 'en']);

        app()->setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        return $next($request);
    }
}
