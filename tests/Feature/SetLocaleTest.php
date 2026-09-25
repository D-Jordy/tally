<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    public static function acceptLanguages(): array
    {
        return [
            'dutch' => ['nl-NL,nl;q=0.9', 'nl'],
            'dutch above english' => ['nl-NL,en-US;q=0.8', 'nl'],
            'english above dutch' => ['en-US,nl;q=0.8', 'en'],
            'unsupported language' => ['de-DE,de;q=0.9', 'en'],
            'no header' => [null, 'en'],
        ];
    }

    #[DataProvider('acceptLanguages')]
    public function test_it_picks_the_locale_from_accept_language(?string $header, string $expected): void
    {
        $request = Request::create('/');
        $request->headers->set('Accept-Language', $header);

        (new SetLocale)->handle($request, fn (Request $request): Response => new Response);

        $this->assertSame($expected, app()->getLocale());
    }
}
