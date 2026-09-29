<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as FrameworkPreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/*
 * SEC-AUTH-01D — Laravel 13 renamed the CSRF middleware to PreventRequestForgery
 * and added Sec-Fetch-Site origin verification. MONEVA must keep:
 *  - the Bearer API (api/*) exempt, so API writes never become 419;
 *  - request forgery protection active on everything else (web routes).
 *
 * The framework middleware skips itself whenever runningUnitTests() is true,
 * so an HTTP feature test cannot prove its behavior. The behavioral cases
 * below therefore run MONEVA's real middleware class through an in-memory
 * probe that only turns that bypass off. No database is used.
 */
class RequestForgeryCompatibilityTest extends TestCase
{
    private function probe(): PreventRequestForgery
    {
        return new class($this->app, $this->app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function runThrough(
        PreventRequestForgery $middleware,
        string $method,
        string $uri,
        array $headers = [],
        bool $withValidToken = false
    ): string {
        $session = $this->app['session']->driver('array');
        $session->start();

        $request = Request::create($uri, $method, $withValidToken ? ['_token' => $session->token()] : []);
        $request->headers->add($headers);
        $request->setLaravelSession($session);

        try {
            $middleware->handle($request, fn () => new Response('passed'));

            return 'passed';
        } catch (TokenMismatchException) {
            return 'rejected';
        }
    }

    public function test_sanctum_uses_the_laravel_13_moneva_request_forgery_middleware(): void
    {
        $this->assertSame(PreventRequestForgery::class, config('sanctum.middleware.validate_csrf_token'));
        $this->assertNull(config('sanctum.middleware.verify_csrf_token'));
    }

    public function test_moneva_middleware_extends_prevent_request_forgery_and_old_class_is_gone(): void
    {
        $this->assertSame(FrameworkPreventRequestForgery::class, get_parent_class(PreventRequestForgery::class));
        $this->assertFalse(class_exists('App\\Http\\Middleware\\VerifyCsrfToken'));

        $web = $this->app->make(HttpKernel::class)->getMiddlewareGroups()['web'];

        $this->assertContains(PreventRequestForgery::class, $web);
        foreach ($web as $middleware) {
            $this->assertStringNotContainsString('VerifyCsrfToken', $middleware);
            $this->assertStringNotContainsString('ValidateCsrfToken', $middleware);
        }
    }

    public function test_api_paths_are_the_only_exemption(): void
    {
        $this->assertSame(['api/*'], $this->app->make(PreventRequestForgery::class)->getExcludedPaths());
    }

    public function test_the_unmodified_middleware_is_bypassed_under_unit_tests(): void
    {
        // Documents why the probe is required: without it, a forged web POST passes.
        $plain = $this->app->make(PreventRequestForgery::class);

        $this->assertSame('passed', $this->runThrough($plain, 'POST', '/web-form', ['Sec-Fetch-Site' => 'cross-site']));
    }

    public function test_bearer_api_writes_are_never_rejected_for_missing_csrf_token(): void
    {
        $probe = $this->probe();

        foreach (['POST' => '/api/expenses', 'PUT' => '/api/expenses/1', 'DELETE' => '/api/budgets/1'] as $method => $uri) {
            $this->assertSame('passed', $this->runThrough($probe, $method, $uri, ['Sec-Fetch-Site' => 'cross-site']), "$method $uri");
            $this->assertSame('passed', $this->runThrough($probe, $method, $uri), "$method $uri without Sec-Fetch-Site");
        }
    }

    public function test_web_writes_without_token_are_rejected(): void
    {
        $probe = $this->probe();

        $this->assertSame('rejected', $this->runThrough($probe, 'POST', '/web-form', ['Sec-Fetch-Site' => 'cross-site']));
        $this->assertSame('rejected', $this->runThrough($probe, 'POST', '/web-form'));
        $this->assertSame('rejected', $this->runThrough($probe, 'POST', '/web-form', ['Sec-Fetch-Site' => 'same-site']));
    }

    public function test_laravel_13_origin_verification_and_token_path_are_active_for_web_routes(): void
    {
        $probe = $this->probe();

        // New in Laravel 13: a same-origin browser request is trusted via Sec-Fetch-Site.
        $this->assertSame('passed', $this->runThrough($probe, 'POST', '/web-form', ['Sec-Fetch-Site' => 'same-origin']));

        // The classic CSRF token path still works for cross-site requests.
        $this->assertSame('passed', $this->runThrough($probe, 'POST', '/web-form', ['Sec-Fetch-Site' => 'cross-site'], true));

        // Reads are never verified.
        $this->assertSame('passed', $this->runThrough($probe, 'GET', '/web-form', ['Sec-Fetch-Site' => 'cross-site']));
    }
}
