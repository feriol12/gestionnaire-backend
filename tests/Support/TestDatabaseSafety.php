<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * SEC-AUTH-00 — Test Safety Foundation.
 *
 * Refuses to let automated tests run against anything but an explicitly
 * named, disposable test database. This class is split into two halves on
 * purpose:
 *
 *   - pure, static predicates (isEnvironmentAllowed, isDriverAllowed,
 *     isDatabaseNameAllowed, isConnectionUrlAllowed, isConfigurationSafe)
 *     that take plain scalars and never touch the framework, so they can be
 *     unit-tested with synthetic "unsafe" values (e.g. gestionnaire_depense)
 *     without ever booting the app or opening a connection;
 *
 *   - guardApplication(), the only method that reads a booted application's
 *     resolved database configuration and applies the guard for real.
 */
final class TestDatabaseSafety
{
    private const ALLOWED_ENVIRONMENT = 'testing';

    private const ALLOWED_DRIVERS = ['mysql', 'pgsql'];

    /**
     * Explicit suffix convention: the name must have a non-empty prefix
     * followed by a literal "_test", using only lowercase letters, digits
     * and underscores. This is intentionally NOT a substring/contains()
     * check — "gestionnaire_test_backup" and "test_production" must both
     * be refused, and both fail this pattern.
     *
     * \A and \z (not ^ and $) anchor to the true start/end of the string,
     * so this never matches a name with leading/trailing whitespace or a
     * newline — the value is validated as-is, never trimmed. See F-02.
     */
    private const TEST_DATABASE_NAME_PATTERN = '/\A[a-z0-9_]+_test\z/';

    public static function isEnvironmentAllowed(string $environment): bool
    {
        return $environment === self::ALLOWED_ENVIRONMENT;
    }

    public static function isDriverAllowed(string $driver): bool
    {
        return in_array($driver, self::ALLOWED_DRIVERS, true);
    }

    public static function isDatabaseNameAllowed(string $database): bool
    {
        return preg_match(self::TEST_DATABASE_NAME_PATTERN, $database) === 1;
    }

    /**
     * Fail-closed policy: an unexpected/non-empty DATABASE_URL (or a
     * connection's resolved "url" config key) is never trusted, even if it
     * looks like it might point at a *_test database. Parsing an arbitrary
     * DSN to prove it is safe is more code, more edge cases, and more ways
     * to get it wrong than simply requiring DB_* variables instead — so
     * any non-empty value here is refused outright.
     *
     * The value is validated as-is: only exactly null or exactly "" are
     * safe. A whitespace-only string (" ", "\t", "\n", ...) is non-empty
     * and must be refused, not trimmed into safety. See F-02.
     */
    public static function isConnectionUrlAllowed(?string $url): bool
    {
        return $url === null || $url === '';
    }

    public static function isConfigurationSafe(
        string $environment,
        string $driver,
        string $database,
        ?string $connectionUrl
    ): bool {
        return self::isEnvironmentAllowed($environment)
            && self::isDriverAllowed($driver)
            && self::isDatabaseNameAllowed($database)
            && self::isConnectionUrlAllowed($connectionUrl);
    }

    private static function describe(
        string $environment,
        string $driver,
        string $database,
        ?string $connectionUrl
    ): string {
        return sprintf(
            'environment=%s driver=%s database=%s connection_url=%s',
            $environment !== '' ? $environment : '(empty)',
            $driver !== '' ? $driver : '(empty)',
            $database !== '' ? $database : '(empty)',
            self::isConnectionUrlAllowed($connectionUrl) ? '(empty)' : '(non-empty, redacted)'
        );
    }

    /**
     * @throws RuntimeException when the configuration is not safe for
     *                           automated testing.
     */
    public static function guard(
        string $environment,
        string $driver,
        string $database,
        ?string $connectionUrl
    ): void {
        if (self::isConfigurationSafe($environment, $driver, $database, $connectionUrl)) {
            return;
        }

        throw new RuntimeException(
            'SEC-AUTH-00: refusing to run automated tests against an unsafe database configuration ('
            .self::describe($environment, $driver, $database, $connectionUrl).'). '
            .'Automated tests are only allowed when APP_ENV=testing, the database driver is mysql or pgsql, '
            .'the database name ends with "_test", and no DATABASE_URL/connection url is set.'
        );
    }

    /**
     * Reads Laravel's fully resolved database configuration off an already
     * booted application and applies the guard. Must be called after the
     * application's service providers/config have been bootstrapped, and
     * before any migration or RefreshDatabase logic can run.
     */
    public static function guardApplication(Application $app): void
    {
        $environment = $app->environment();

        $config = $app->make('config');
        $connectionName = (string) $config->get('database.default', '');
        $connection = (array) $config->get("database.connections.{$connectionName}", []);

        $driver = (string) ($connection['driver'] ?? '');
        $database = (string) ($connection['database'] ?? '');
        $url = $connection['url'] ?? null;
        $url = $url === null ? null : (string) $url;

        self::guard($environment, $driver, $database, $url);
    }
}
