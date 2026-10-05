<?php

/*
 * Bascule Neon : diagnostic et garde de la base cible au démarrage du conteneur.
 *
 *   php docker/db-target-guard.php raw     diagnostic des variables brutes ; sort toujours 0
 *                                          et n'autorise jamais rien
 *   php docker/db-target-guard.php cached  contrôle de la configuration EFFECTIVE : cache écrit
 *                                          par config:cache, résolu par le parser d'URL de
 *                                          Laravel ; sort 1 (BLOCKED) au moindre doute
 *
 * N'affiche QUE des classifications (SET/MISSING/EMPTY, NEON/RENDER/OTHER/INVALID/N/A,
 * PGSQL/OTHER…) : jamais d'URL, d'hôte, d'utilisateur, de mot de passe, de base, de port ni
 * de paramètres. Ne démarre pas Laravel (aucun service provider), ne crée ni connexion ni
 * PDO, ne fait ni DNS ni réseau.
 */

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\ConfigurationUrlParser;

const PG_SCHEMES = ['pgsql', 'postgres', 'postgresql'];

// Champs de configuration que PostgresConnector (Laravel 10) écrit dans le DSN.
const DSN_FIELDS = [
    'host', 'port', 'database', 'connect_via_database', 'connect_via_port', 'charset',
    'application_name', 'sslmode', 'sslcert', 'sslkey', 'sslrootcert',
];

function guard_line(string $text): void
{
    fwrite(STDOUT, 'DB_GUARD '.$text.PHP_EOL);
}

function guard_blocked(): int
{
    guard_line('RESULT=BLOCKED');

    return 1;
}

// Nom d'hôte DNS valide (253 caractères max, labels de 1 à 63) puis classification.
function host_class(mixed $host): string
{
    if (! is_string($host) || $host === '' || strlen($host) > 253) {
        return 'INVALID';
    }

    $host = strtolower($host);

    foreach (explode('.', $host) as $label) {
        if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
            return 'INVALID';
        }
    }

    // Endpoint Neon direct ou pooler : …neon.tech (et non evilneon.tech ni neon.tech.x).
    if (str_ends_with($host, '.neon.tech')) {
        return 'NEON';
    }

    // Hôte Render historique : interne « dpg-… » ou externe « dpg-….render.com ».
    if (str_starts_with($host, 'dpg-') && (! str_contains($host, '.') || str_ends_with($host, '.render.com'))) {
        return 'RENDER';
    }

    return 'OTHER';
}

/**
 * Variable brute (diagnostic) : autorité de l'URL seulement.
 *
 * @return array{0: string, 1: string} [état de la valeur, classe de l'hôte]
 */
function classify_raw(mixed $value): array
{
    if ($value === false || $value === null) {
        return ['MISSING', 'N/A'];
    }

    if (! is_string($value)) {
        return ['SET', 'INVALID'];
    }

    if ($value === '') {
        return ['EMPTY', 'N/A'];
    }

    $parts = parse_url($value);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

    if (! in_array($scheme, PG_SCHEMES, true)) {
        return ['SET', 'INVALID'];
    }

    return ['SET', host_class($parts['host'] ?? null)];
}

// Configuration pgsql effective : même résolution que DatabaseManager::configuration().
function effective_pgsql_config(array $cached): ?array
{
    $config = $cached['database']['connections']['pgsql'] ?? null;

    if (! is_array($config)) {
        return null;
    }

    try {
        return (new ConfigurationUrlParser)->parseConfiguration($config);
    } catch (InvalidArgumentException) {
        return null; // URL que Laravel refuse de lire
    }
}

// DSN sans ambiguïté : aucun séparateur ni quote dans les champs du DSN (addSslOptions
// n'échappe rien), puis vérification sur le DSN réellement construit par Laravel.
function dsn_is_unambiguous(array $config): bool
{
    foreach (DSN_FIELDS as $field) {
        $value = $config[$field] ?? null;

        if ($value !== null && (! is_scalar($value) || ! preg_match('#^[A-Za-z0-9._/:-]*$#', (string) $value))) {
            return false;
        }
    }

    $dsn = (fn (array $c) => $this->getDsn($c))->call(new PostgresConnector, $config);

    return substr_count($dsn, 'host=') === 1;
}

function guard_raw(): int
{
    guard_line('RAW_MODE=DIAGNOSTIC_ONLY');

    foreach (['NEON_DATABASE_URL', 'DATABASE_URL'] as $name) {
        [$state, $class] = classify_raw(getenv($name));
        guard_line("RAW {$name}={$state} HOST_CLASS={$class}");
    }

    return 0;
}

function guard_cached(): int
{
    $base = dirname(__DIR__);

    // Un cache ailleurs (APP_CONFIG_CACHE, même vide, ou via un .env absent de l'image)
    // n'est pas pris en charge : bloquer plutôt que lire un autre fichier que config:cache.
    if (getenv('APP_CONFIG_CACHE') !== false || is_file($base.'/.env')) {
        guard_line('CACHED_CONFIG_PATH=CUSTOM_UNSUPPORTED');

        return guard_blocked();
    }

    $file = $base.'/bootstrap/cache/config.php';
    $cached = is_file($file) ? require $file : null;

    if (! is_array($cached)) {
        guard_line('CACHED_CONFIG=UNREADABLE');

        return guard_blocked();
    }

    // Sans le parser de Laravel, la configuration effective est inconnue.
    if (! is_file($base.'/vendor/autoload.php')) {
        guard_line('CACHED_LARAVEL_PARSER=UNAVAILABLE');

        return guard_blocked();
    }

    require_once $base.'/vendor/autoload.php';

    // Connexion utilisée par migrate et par l'application : elle doit être pgsql.
    $default = ($cached['database']['default'] ?? null) === 'pgsql' ? 'PGSQL' : 'OTHER';
    guard_line("CACHED_DEFAULT_CONNECTION={$default}");

    $config = effective_pgsql_config($cached);

    if ($config === null) {
        guard_line('CACHED_EFFECTIVE_CONFIG=UNPARSABLE');

        return guard_blocked();
    }

    $driver = ($config['driver'] ?? null) === 'pgsql' ? 'PGSQL' : 'OTHER';

    // Laravel bascule en lecture/écriture séparées dès que « read » existe, et la clé
    // « write » remplace alors l'hôte : non utilisé par MONEVA, donc refusé.
    $readWrite = array_key_exists('read', $config) || array_key_exists('write', $config) ? 'UNSUPPORTED' : 'NONE';

    // Plusieurs hôtes (tableau) : Laravel en choisit un au hasard, refusé.
    $hostClass = is_array($config['host'] ?? null) ? 'MULTIPLE' : host_class($config['host'] ?? null);

    $dsn = $driver === 'PGSQL' && is_string($config['host'] ?? null)
        ? (dsn_is_unambiguous($config) ? 'UNAMBIGUOUS' : 'AMBIGUOUS')
        : 'NOT_CHECKED';

    guard_line("CACHED_EFFECTIVE_DRIVER={$driver}");
    guard_line("CACHED_READ_WRITE={$readWrite}");
    guard_line("CACHED_EFFECTIVE_HOST_CLASS={$hostClass}");
    guard_line("CACHED_DSN={$dsn}");

    if ($default !== 'PGSQL' || $driver !== 'PGSQL' || $readWrite !== 'NONE' || $hostClass !== 'NEON' || $dsn !== 'UNAMBIGUOUS') {
        return guard_blocked();
    }

    guard_line('RESULT=PASS');

    return 0;
}

// Exécution directe seulement (les tests chargent les fonctions sans lancer la garde).
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $mode = $argv[1] ?? '';

    // Aucun message d'erreur PHP (il pourrait citer une valeur) : erreur = BLOCKED générique.
    ini_set('display_errors', '0');
    set_error_handler(static function (int $severity): never {
        throw new ErrorException('guard error', 0, $severity);
    });

    try {
        exit(match ($mode) {
            'raw' => guard_raw(),
            'cached' => guard_cached(),
            default => (static function (): int {
                guard_line('USAGE=raw|cached');

                return 2;
            })(),
        });
    } catch (Throwable) {
        if ($mode === 'raw') {
            // Diagnostic seulement : la décision revient à l'étape « cached ».
            guard_line('RAW=GUARD_ERROR');
            exit(0);
        }

        guard_line('CACHED=GUARD_ERROR');
        exit(guard_blocked());
    }
}
