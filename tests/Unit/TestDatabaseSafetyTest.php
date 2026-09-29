<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

/**
 * SEC-AUTH-00 — pure logic tests for the test-database safety guard.
 *
 * This class deliberately extends the raw PHPUnit\Framework\TestCase, NOT
 * Tests\TestCase: it must never boot the Laravel application and must never
 * open a database connection. Every "unsafe" value below (including
 * gestionnaire_depense) is used only as a plain string input to a pure
 * predicate — it is never used to actually connect anywhere.
 */
class TestDatabaseSafetyTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, ?string, bool}>
     */
    public static function configurationProvider(): array
    {
        return [
            // CASE A
            'testing/mysql/gestionnaire_test -> accept' => [
                'testing', 'mysql', 'gestionnaire_test', null, true,
            ],
            // CASE B
            'testing/mysql/gestionnaire_depense -> refuse' => [
                'testing', 'mysql', 'gestionnaire_depense', null, false,
            ],
            // CASE C
            'testing/pgsql/postgres -> refuse' => [
                'testing', 'pgsql', 'postgres', null, false,
            ],
            // CASE D
            'testing/pgsql/moneva_auth_v3_test -> accept' => [
                'testing', 'pgsql', 'moneva_auth_v3_test', null, true,
            ],
            // CASE E
            'testing/sqlite/:memory: -> refuse (driver not allowed)' => [
                'testing', 'sqlite', ':memory:', null, false,
            ],
            // CASE F
            'local/mysql/gestionnaire_test -> refuse (env not allowed)' => [
                'local', 'mysql', 'gestionnaire_test', null, false,
            ],
            // CASE G
            'testing/mysql/moneva_security_test -> accept' => [
                'testing', 'mysql', 'moneva_security_test', null, true,
            ],
            // CASE H
            'testing/mysql/production_test_backup -> refuse (naming convention)' => [
                'testing', 'mysql', 'production_test_backup', null, false,
            ],
            // CASE I
            'testing/mysql/gestionnaire_test + non-empty DATABASE_URL -> refuse (fail closed)' => [
                'testing', 'mysql', 'gestionnaire_test', 'mysql://user:pass@host/gestionnaire_depense', false,
            ],

            // F-02 boundary cases: whitespace/newlines must never be
            // trimmed away before the safety decision is made.
            'F-02.1: leading and trailing spaces around a safe name -> refuse' => [
                'testing', 'mysql', ' gestionnaire_test ', null, false,
            ],
            'F-02.2: trailing space only after a safe name -> refuse' => [
                'testing', 'mysql', 'gestionnaire_test ', null, false,
            ],
            'F-02.3: trailing newline after a safe name -> refuse' => [
                'testing', 'mysql', "gestionnaire_test\n", null, false,
            ],
            'F-02.4: leading newline before a safe name -> refuse' => [
                'testing', 'mysql', "\ngestionnaire_test", null, false,
            ],
            'F-02.5: connection URL of a single space -> refuse' => [
                'testing', 'mysql', 'gestionnaire_test', ' ', false,
            ],
            'F-02.6: connection URL of a tab -> refuse' => [
                'testing', 'mysql', 'gestionnaire_test', "\t", false,
            ],
            'F-02.7: connection URL of a newline -> refuse' => [
                'testing', 'mysql', 'gestionnaire_test', "\n", false,
            ],
            'F-02.8: empty-string connection URL -> accept when everything else is safe' => [
                'testing', 'mysql', 'gestionnaire_test', '', true,
            ],
            'F-02.9: null connection URL -> accept when everything else is safe' => [
                'testing', 'mysql', 'gestionnaire_test', null, true,
            ],
        ];
    }

    #[DataProvider('configurationProvider')]
    public function test_configuration_safety_matches_expected_decision(
        string $environment,
        string $driver,
        string $database,
        ?string $connectionUrl,
        bool $expectedSafe
    ): void {
        $this->assertSame(
            $expectedSafe,
            TestDatabaseSafety::isConfigurationSafe($environment, $driver, $database, $connectionUrl)
        );
    }

    public function test_guard_throws_for_the_real_development_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/refusing to run automated tests/');

        TestDatabaseSafety::guard('testing', 'mysql', 'gestionnaire_depense', null);
    }

    public function test_guard_does_not_throw_for_an_explicit_test_database(): void
    {
        TestDatabaseSafety::guard('testing', 'mysql', 'gestionnaire_test', null);

        $this->addToAssertionCount(1);
    }

    public function test_guard_fails_closed_on_non_empty_connection_url_even_if_otherwise_valid(): void
    {
        $this->expectException(RuntimeException::class);

        TestDatabaseSafety::guard(
            'testing',
            'pgsql',
            'moneva_security_test',
            'pgsql://user:pass@host/moneva_security_test'
        );
    }

    public function test_bare_test_suffix_without_prefix_is_refused(): void
    {
        $this->assertFalse(TestDatabaseSafety::isDatabaseNameAllowed('_test'));
        $this->assertFalse(TestDatabaseSafety::isDatabaseNameAllowed(''));
    }

    /**
     * F-02: the database name predicate must validate the original string,
     * never a trimmed copy of it.
     */
    public function test_database_name_is_not_trimmed_before_validation(): void
    {
        $this->assertTrue(TestDatabaseSafety::isDatabaseNameAllowed('gestionnaire_test'));

        $this->assertFalse(TestDatabaseSafety::isDatabaseNameAllowed(' gestionnaire_test '));
        $this->assertFalse(TestDatabaseSafety::isDatabaseNameAllowed('gestionnaire_test '));
        $this->assertFalse(TestDatabaseSafety::isDatabaseNameAllowed(' gestionnaire_test'));
        $this->assertFalse(TestDatabaseSafety::isDatabaseNameAllowed("gestionnaire_test\n"));
        $this->assertFalse(TestDatabaseSafety::isDatabaseNameAllowed("\ngestionnaire_test"));
        $this->assertFalse(TestDatabaseSafety::isDatabaseNameAllowed("gestionnaire_test\t"));
    }

    /**
     * F-02: the connection-url predicate must accept only exactly null or
     * exactly "" — never a trimmed copy of a whitespace-only string.
     */
    public function test_connection_url_is_not_trimmed_before_validation(): void
    {
        $this->assertTrue(TestDatabaseSafety::isConnectionUrlAllowed(null));
        $this->assertTrue(TestDatabaseSafety::isConnectionUrlAllowed(''));

        $this->assertFalse(TestDatabaseSafety::isConnectionUrlAllowed(' '));
        $this->assertFalse(TestDatabaseSafety::isConnectionUrlAllowed("\t"));
        $this->assertFalse(TestDatabaseSafety::isConnectionUrlAllowed("\n"));
        $this->assertFalse(TestDatabaseSafety::isConnectionUrlAllowed('  '));
    }
}
