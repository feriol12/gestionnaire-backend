<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Expense;
use App\Models\User;
use Barryvdh\DomPDF\PDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
 *  - R-01 : l'export annuel des budgets (period=year) fonctionne, ne contient
 *    que les budgets de l'utilisateur pour l'année en cours, et l'export
 *    mensuel reste inchangé.
 */
class ExportSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-15 12:00:00';

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    // ---------------------------------------------------------------- R-01

    private function budgetFor(User $user, string $month, int $amount): Budget
    {
        return Budget::create(['user_id' => $user->id, 'month' => $month, 'amount' => $amount]);
    }

    /**
     * Current year 2026: two own budgets in 2026 (one outside the current
     * month), one own budget in 2025, and a foreign budget in 2026.
     */
    private function seedBudgetsAcrossYearsAndUsers(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW));

        $this->budgetFor($this->user, '2026-03-01', 111111);
        $this->budgetFor($this->user, '2026-09-01', 222222);
        $this->budgetFor($this->user, '2025-12-01', 555555);
        $this->budgetFor(User::factory()->create(), '2026-05-01', 444444);
    }

    private function exportBudgets(string $period, ?string &$html): \Illuminate\Testing\TestResponse
    {
        $this->captureHtmlPassedToDompdf($html);

        $response = $this->get('/api/export/budgets/pdf?period='.$period);

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertIsString($html);

        return $response;
    }

    public function test_annual_budget_export_contains_only_own_budgets_of_the_current_year(): void
    {
        $this->seedBudgetsAcrossYearsAndUsers();
        Exceptions::fake();

        $html = null;
        $response = $this->exportBudgets('year', $html);

        Exceptions::assertNothingReported(); // no undefined scope / BadMethodCallException
        $this->assertStringContainsString('budgets_year.pdf', (string) $response->headers->get('Content-Disposition'));

        // Own budgets of the current year, including a month other than the current one.
        $this->assertStringContainsString('<td>03/2026</td>', $html);
        $this->assertStringContainsString('<td>09/2026</td>', $html);
        $this->assertStringContainsString('111 111 FCFA', $html);
        $this->assertStringContainsString('222 222 FCFA', $html);
        $this->assertStringContainsString('333 333 FCFA', $html); // total of the two

        // Own budget of another year: excluded.
        $this->assertStringNotContainsString('<td>12/2025</td>', $html);
        $this->assertStringNotContainsString('555 555', $html);

        // Another user's budget of the same year: excluded.
        $this->assertStringNotContainsString('<td>05/2026</td>', $html);
        $this->assertStringNotContainsString('444 444', $html);

        // Newest month first, as before.
        $this->assertLessThan(strpos($html, '<td>03/2026</td>'), strpos($html, '<td>09/2026</td>'));
    }

    public function test_annual_budget_export_of_a_year_without_budgets_is_an_empty_valid_pdf(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW));
        $this->budgetFor($this->user, '2025-12-01', 555555);
        Exceptions::fake();

        $html = null;
        $this->exportBudgets('year', $html);

        Exceptions::assertNothingReported();
        $this->assertStringNotContainsString('555 555', $html);
        $this->assertStringNotContainsString('<td>12/2025</td>', $html);
        $this->assertStringContainsString('0 FCFA', $html);
    }

    public function test_monthly_budget_export_is_unchanged(): void
    {
        $this->seedBudgetsAcrossYearsAndUsers();
        Exceptions::fake();

        $html = null;
        $response = $this->exportBudgets('month', $html);

        Exceptions::assertNothingReported();
        $this->assertStringContainsString('budgets_month.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('<td>09/2026</td>', $html);
        $this->assertStringContainsString('222 222 FCFA', $html);
        $this->assertStringNotContainsString('<td>03/2026</td>', $html);
        $this->assertStringNotContainsString('<td>12/2025</td>', $html);
        $this->assertStringNotContainsString('<td>05/2026</td>', $html);
    }
}
