<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Expense;
use App\Models\User;
use Barryvdh\DomPDF\PDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/*
 * SEC-AUTH-02A — exports PDF :
 *  - SEC-002 : une erreur d'export ne divulgue aucun détail interne au client,
 *    mais reste signalée côté serveur (report()).
 *  - SEC-005 : les valeurs utilisateur sont échappées dans le HTML envoyé à
 *    DomPDF, et le PDF réel reste produit.
 */
class ExportSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'INTERNAL_TEST_SECRET';

    private const IMG_PAYLOAD = '<img src=x onerror=alert(1)>';

    private const BOLD_PAYLOAD = '<b>SECURITY_TEST</b>';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /**
     * Replaces the DomPDF wrapper with one whose loadHTML() fails with an
     * exception carrying internal-looking details.
     */
    private function makePdfGenerationFail(): void
    {
        $this->app->bind('dompdf.wrapper', fn () => new class
        {
            public function loadHTML(string $string, ?string $encoding = null)
            {
                throw new RuntimeException(
                    'INTERNAL_TEST_SECRET in C:\\secret\\app\\Export.php and /var/www/html/app/Export.php'
                );
            }
        });
    }

    /**
     * Keeps the real DomPDF wrapper but records the HTML it receives.
     */
    private function captureHtmlPassedToDompdf(?string &$captured): void
    {
        $spy = function (string $html) use (&$captured) {
            $captured = $html;
        };

        $this->app->bind('dompdf.wrapper', fn ($app) => new class($app['dompdf'], $app['config'], $app['files'], $app['view'], $spy) extends PDF
        {
            public function __construct($dompdf, $config, $files, $view, private \Closure $spy)
            {
                parent::__construct($dompdf, $config, $files, $view);
            }

            public function loadHTML(string $string, ?string $encoding = null): self
            {
                ($this->spy)($string);

                return parent::loadHTML($string, $encoding);
            }
        });
    }

    private function assertNoInternalDetails(\Illuminate\Testing\TestResponse $response): void
    {
        $body = $response->getContent();

        foreach ([self::SECRET, 'C:\\', 'C:\\\\', '/var/www', 'RuntimeException', 'Export.php', basename(__FILE__), '"line"', '"file"', 'trace'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "response leaks [$needle]");
        }
    }

    public function test_expense_export_failure_returns_a_generic_error_and_reports_it(): void
    {
        config(['app.debug' => true]); // protection must not depend on APP_DEBUG
        Exceptions::fake();
        $this->makePdfGenerationFail();

        $response = $this->getJson('/api/export/pdf');

        $response->assertStatus(500)->assertExactJson([
            'success' => false,
            'error' => 'Impossible de générer le PDF des dépenses.',
        ]);
        $this->assertNoInternalDetails($response);

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), self::SECRET));
    }

    public function test_budget_export_failure_returns_a_generic_error_and_reports_it(): void
    {
        config(['app.debug' => true]);
        Exceptions::fake();
        $this->makePdfGenerationFail();

        $response = $this->getJson('/api/export/budgets/pdf');

        $response->assertStatus(500)->assertExactJson([
            'success' => false,
            'error' => 'Impossible de générer le PDF des budgets.',
        ]);
        $this->assertNoInternalDetails($response);

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), self::SECRET));
    }

    public function test_expense_export_escapes_user_values_and_still_produces_a_pdf(): void
    {
        foreach ([self::BOLD_PAYLOAD, self::IMG_PAYLOAD] as $description) {
            Expense::create([
                'user_id' => $this->user->id,
                'description' => $description,
                'amount' => 15000,
                'category' => 'Beauté & soins',
                'date' => now()->toDateString(),
            ]);
        }

        $html = null;
        $this->captureHtmlPassedToDompdf($html);

        $response = $this->get('/api/export/pdf?period='.urlencode(self::IMG_PAYLOAD));

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->assertIsString($html);
        // Raw markup never reaches DomPDF as markup...
        $this->assertStringNotContainsString(self::BOLD_PAYLOAD, $html);
        $this->assertStringNotContainsString(self::IMG_PAYLOAD, $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<img', $html);
        // ...but its escaped, readable representation does (description + period title).
        $this->assertStringContainsString('&lt;b&gt;SECURITY_TEST&lt;/b&gt;', $html);
        $this->assertSame(2, substr_count($html, '&lt;img src=x onerror=alert(1)&gt;'));
        // Ordinary punctuation is kept, only HTML-encoded.
        $this->assertStringContainsString('Beauté &amp; soins', $html);
        $this->assertStringContainsString('<td>'.now()->toDateString(), $html);
    }

    public function test_budget_export_escapes_user_values_and_still_produces_a_pdf(): void
    {
        Budget::create([
            'user_id' => $this->user->id,
            'month' => now()->startOfMonth()->toDateString(),
            'amount' => 250000,
        ]);

        $html = null;
        $this->captureHtmlPassedToDompdf($html);

        $response = $this->get('/api/export/budgets/pdf?period='.urlencode(self::IMG_PAYLOAD));

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->assertIsString($html);
        $this->assertStringNotContainsString(self::IMG_PAYLOAD, $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Budgets - &lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString(now()->format('m/Y'), $html);
        $this->assertStringContainsString('250 000 FCFA', $html);
    }

    public function test_dompdf_remote_content_and_php_execution_stay_disabled(): void
    {
        $options = $this->app->make('dompdf')->getOptions();

        $this->assertFalse($options->getIsRemoteEnabled());
        $this->assertFalse($options->getIsPhpEnabled());
    }
}
