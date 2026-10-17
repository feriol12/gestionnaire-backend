<?php

namespace App\Queue\SingleWorker;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/*
 * LWS-BE-06C — configuration validée du worker unique et contrat de sortie.
 *
 * Tout est lu depuis la configuration (variables QUEUE_WORKER_*), sans valeur
 * par défaut : une valeur absente ou invalide lève InvalidArgumentException et
 * l'appelant sort en code 2 SANS tenter le verrou, sans écrire de journal sur
 * disque, sans publier de garde et sans démarrer de worker.
 *
 * Frontières (V2, revue Codex B2) :
 *  - racine privée QUEUE_WORKER_OPS_ROOT (production /home/fluxmoneva-ops) :
 *    dossier existant, canonique, dédié ; tout chemin opérationnel doit s'y
 *    trouver (liste d'autorisation) ;
 *  - racines publiques QUEUE_WORKER_PUBLIC_ROOTS (production /home/htdocs ;
 *    liste séparée par des virgules, non vide) : aucun chemin opérationnel ni
 *    la racine privée ne peut s'y trouver, sous leur forme littérale comme
 *    canonique (alias symboliques compris) ;
 *  - l'application (base_path) est toujours exclue.
 *
 * Chemins (verrou, garde, journal, capture Cron facultative) :
 *  - absolus, sans segment relatif, nom de fichier simple [A-Za-z0-9._-] ;
 *  - dossier parent existant et canonique (aucun lien symbolique), jamais créé ;
 *  - le fichier n'est pas un lien symbolique et, s'il existe, est un fichier.
 *
 * Espaces de noms (V2, revue Codex B3) : le verrou et la garde (contrôle) ne
 * partagent JAMAIS le dossier du journal ni celui de la capture Cron
 * (observabilité). La rotation quotidienne n'écrit et ne supprime que des
 * fichiers « <nom>-AAAA-MM-JJ.<ext> » du dossier du journal : elle ne peut donc
 * ni écrire dans le verrou ou la garde, ni les supprimer. La capture Cron ne
 * peut pas porter un nom de fichier quotidien du journal.
 *
 * Paramètres du worker : entiers de 1 à MAX_INTEGER, PROVISOIRES (fixés en
 * LWS-BE-06D). Invariants : timeout < retry_after (valeur courante de la
 * connexion database, non figée ici) et max_time + timeout <= runtime_budget
 * (budget de configuration, pas une borne d'horloge stricte : démarrage,
 * pauses, base de données et journal s'y ajoutent).
 */
final class WorkerSettings
{
    // Contrat de sortie des commandes ops:queue-*. Le worker Laravel renvoie
    // 0, 1 ou 12 (propagés tels quels) ; aucune collision avec 2, 74 et 75.
    public const EXIT_SUCCESS = 0;

    public const EXIT_FAILURE = 1;

    public const EXIT_INVALID_CONFIG = 2;

    public const EXIT_LOCK_ERROR = 74;   // sysexits EX_IOERR

    public const EXIT_SKIPPED = 75;      // sysexits EX_TEMPFAIL

    // Invocation structurelle de queue:work (fixée par la conception 06C).
    public const CONNECTION = 'database';

    public const QUEUE = 'default';

    public const WORKER_NAME = 'fluxmoneva-single';

    // Garde de représentabilité (pas un réglage) : secondes ou mégaoctets.
    public const MAX_INTEGER = 86_400;

    private function __construct(
        public readonly string $lockPath,
        public readonly string $pausePath,
        public readonly string $logPath,
        public readonly int $maxTime,
        public readonly int $timeout,
        public readonly int $sleep,
        public readonly int $memory,
        public readonly int $runtimeBudget,
        public readonly int $retryAfter,
    ) {
    }

    /**
     * Chemins du domaine d'exclusion, validés ENSEMBLE (frontières et espaces
     * de noms) avant toute écriture.
     *
     * @return array{lock: string, pause: string, log: string, cron_output: ?string}
     */
    public static function paths(Repository $config, string $basePath): array
    {
        $boundaries = self::boundaries($config, $basePath);

        $paths = [
            'lock' => self::validatePath('QUEUE_WORKER_LOCK_PATH', $config->get('queue_worker.lock_path'), $boundaries),
            'pause' => self::validatePath('QUEUE_WORKER_PAUSE_PATH', $config->get('queue_worker.pause_path'), $boundaries),
            'log' => self::validatePath('QUEUE_WORKER_LOG_PATH', $config->get('logging.channels.queue_ops.path'), $boundaries),
            'cron_output' => null,
        ];

        $cronOutput = $config->get('queue_worker.cron_output_path');

        if ($cronOutput !== null && $cronOutput !== '') {
            $paths['cron_output'] = self::validatePath('QUEUE_WORKER_CRON_OUTPUT_PATH', $cronOutput, $boundaries);
        }

        self::assertSeparateNamespaces($paths);

        return $paths;
    }

    /**
     * Configuration complète du worker (chemins + paramètres + invariants).
     */
    public static function fromConfig(Repository $config, string $basePath): self
    {
        $paths = self::paths($config, $basePath);

        $maxTime = self::positiveInteger('QUEUE_WORKER_MAX_TIME', $config->get('queue_worker.max_time'));
        $timeout = self::positiveInteger('QUEUE_WORKER_TIMEOUT', $config->get('queue_worker.timeout'));
        $sleep = self::positiveInteger('QUEUE_WORKER_SLEEP', $config->get('queue_worker.sleep'));
        $memory = self::positiveInteger('QUEUE_WORKER_MEMORY', $config->get('queue_worker.memory'));
        $budget = self::positiveInteger('QUEUE_WORKER_RUNTIME_BUDGET', $config->get('queue_worker.runtime_budget'));
        $retryAfter = self::positiveInteger('queue.connections.database.retry_after', $config->get('queue.connections.'.self::CONNECTION.'.retry_after'));

        if ($timeout >= $retryAfter) {
            throw new InvalidArgumentException('QUEUE_WORKER_TIMEOUT must be lower than queue.connections.database.retry_after.');
        }

        if ($maxTime + $timeout > $budget) {
            throw new InvalidArgumentException('QUEUE_WORKER_MAX_TIME + QUEUE_WORKER_TIMEOUT must not exceed QUEUE_WORKER_RUNTIME_BUDGET.');
        }

        return new self($paths['lock'], $paths['pause'], $paths['log'], $maxTime, $timeout, $sleep, $memory, $budget, $retryAfter);
    }

    /**
     * Arguments de queue:work : connexion et file fixes, arrêt quand la file
     * est vide, paramètres configurés. Jamais --force (pas de traitement en
     * maintenance), jamais --tries/--backoff (politique portée par chaque
     * tâche), jamais --once.
     *
     * @return array<string, int|string|bool>
     */
    public function workerArguments(): array
    {
        return [
            'connection' => self::CONNECTION,
            '--queue' => self::QUEUE,
            '--name' => self::WORKER_NAME,
            '--stop-when-empty' => true,
            '--max-time' => $this->maxTime,
            '--timeout' => $this->timeout,
            '--sleep' => $this->sleep,
            '--memory' => $this->memory,
        ];
    }

    /**
     * Entier de 1 à MAX_INTEGER (entier PHP ou chaîne de chiffres courte) ;
     * rien d'autre (pas de saturation silencieuse des grandes valeurs).
     */
    public static function positiveInteger(string $key, mixed $value): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,9}$/', $value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1 || $value > self::MAX_INTEGER) {
            throw new InvalidArgumentException("{$key} must be an integer between 1 and ".self::MAX_INTEGER.'.');
        }

        return $value;
    }

    /**
     * @return array{base: list<string>, ops: string, public: list<string>}
     */
    private static function boundaries(Repository $config, string $basePath): array
    {
        $base = array_values(array_unique(array_filter([
            self::comparable($basePath),
            ($real = realpath($basePath)) !== false ? self::comparable($real) : null,
        ])));

        $publicRaw = $config->get('queue_worker.public_roots');
        $publicEntries = is_string($publicRaw) ? array_values(array_filter(array_map('trim', explode(',', $publicRaw)), fn ($v) => $v !== '')) : [];

        if ($publicEntries === []) {
            throw new InvalidArgumentException('QUEUE_WORKER_PUBLIC_ROOTS is not configured.');
        }

        $public = [];

        foreach ($publicEntries as $entry) {
            $normalized = self::normalize($entry);

            if (! self::isAbsolute($normalized) || self::hasRelativeSegment($normalized)) {
                throw new InvalidArgumentException('QUEUE_WORKER_PUBLIC_ROOTS entries must be absolute paths without relative segments.');
            }

            $public[] = self::comparable($normalized);

            if (($real = realpath($normalized)) !== false) {
                $public[] = self::comparable($real);
            }
        }

        $public = array_values(array_unique($public));

        $ops = $config->get('queue_worker.ops_root');

        if (! is_string($ops) || trim($ops) === '') {
            throw new InvalidArgumentException('QUEUE_WORKER_OPS_ROOT is not configured.');
        }

        $ops = self::normalize($ops);

        if (! self::isAbsolute($ops) || self::hasRelativeSegment($ops)) {
            throw new InvalidArgumentException('QUEUE_WORKER_OPS_ROOT must be an absolute path without relative segments.');
        }

        $realOps = realpath($ops);

        if ($realOps === false || ! is_dir($realOps)) {
            throw new InvalidArgumentException('QUEUE_WORKER_OPS_ROOT does not exist.');
        }

        if (self::comparable($realOps) !== self::comparable($ops)) {
            throw new InvalidArgumentException('QUEUE_WORKER_OPS_ROOT must be a canonical path (no symbolic link).');
        }

        $opsComparable = self::comparable($realOps);

        foreach ([...$base, ...$public] as $forbidden) {
            if (self::within($opsComparable, $forbidden) || self::within($forbidden, $opsComparable)) {
                throw new InvalidArgumentException('QUEUE_WORKER_OPS_ROOT must be a dedicated directory, neither inside nor containing the application or a public root.');
            }
        }

        return ['base' => $base, 'ops' => $opsComparable, 'public' => $public];
    }

    /**
     * Valide un chemin opérationnel et le renvoie normalisé (séparateurs « / »).
     *
     * @param  array{base: list<string>, ops: string, public: list<string>}  $boundaries
     */
    private static function validatePath(string $key, mixed $value, array $boundaries): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$key} is not configured.");
        }

        $path = self::normalize($value);

        if (! self::isAbsolute($path)) {
            throw new InvalidArgumentException("{$key} must be an absolute path.");
        }

        if (self::hasRelativeSegment($path)) {
            throw new InvalidArgumentException("{$key} must not contain relative segments.");
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', basename($path))) {
            throw new InvalidArgumentException("{$key} must end with a simple file name ([A-Za-z0-9._-]).");
        }

        $directory = dirname($path);
        $realDirectory = realpath($directory);

        if ($realDirectory === false || ! is_dir($realDirectory)) {
            throw new InvalidArgumentException("{$key}: parent directory does not exist.");
        }

        if (self::comparable($realDirectory) !== self::comparable($directory)) {
            throw new InvalidArgumentException("{$key}: parent directory must be a canonical path (no symbolic link).");
        }

        if (is_link($path)) {
            throw new InvalidArgumentException("{$key} must not be a symbolic link.");
        }

        if (file_exists($path) && ! is_file($path)) {
            throw new InvalidArgumentException("{$key} must be a regular file.");
        }

        $candidate = self::comparable($realDirectory);

        if (! self::within($candidate, $boundaries['ops'])) {
            throw new InvalidArgumentException("{$key} must be inside QUEUE_WORKER_OPS_ROOT.");
        }

        foreach ($boundaries['base'] as $base) {
            if (self::within($candidate, $base)) {
                throw new InvalidArgumentException("{$key} must be outside the application directory.");
            }
        }

        foreach ($boundaries['public'] as $public) {
            if (self::within($candidate, $public)) {
                throw new InvalidArgumentException("{$key} must not be inside a public root (QUEUE_WORKER_PUBLIC_ROOTS).");
            }
        }

        return $path;
    }

    /**
     * Revue Codex B3 : contrôle (verrou, garde) et observabilité (journal
     * quotidien, rétention, capture Cron) dans des espaces disjoints.
     *
     * @param  array{lock: string, pause: string, log: string, cron_output: ?string}  $paths
     */
    private static function assertSeparateNamespaces(array $paths): void
    {
        $named = array_filter($paths);

        if (count(array_unique(array_map(self::comparable(...), $named))) !== count($named)) {
            throw new InvalidArgumentException('QUEUE_WORKER_* operational paths must be distinct.');
        }

        $observability = array_filter([$paths['log'], $paths['cron_output']]);

        foreach (['QUEUE_WORKER_LOCK_PATH' => $paths['lock'], 'QUEUE_WORKER_PAUSE_PATH' => $paths['pause']] as $key => $control) {
            foreach ($observability as $observed) {
                if (self::comparable(dirname($control)) === self::comparable(dirname($observed))) {
                    throw new InvalidArgumentException("{$key} must not share a directory with the log or Cron output files.");
                }
            }
        }

        // Fichiers temporaires de publication de la garde : « .<garde>.*.tmp ».
        if (self::comparable(dirname($paths['lock'])) === self::comparable(dirname($paths['pause']))
            && preg_match('/^\.'.preg_quote(basename($paths['pause']), '/').'\..*\.tmp$/i', basename($paths['lock']))) {
            throw new InvalidArgumentException('QUEUE_WORKER_LOCK_PATH collides with the pause flag temporary files.');
        }

        if ($paths['cron_output'] !== null && self::comparable(dirname($paths['cron_output'])) === self::comparable(dirname($paths['log']))
            && preg_match(self::dailyLogPattern($paths['log']), basename($paths['cron_output']))) {
            throw new InvalidArgumentException('QUEUE_WORKER_CRON_OUTPUT_PATH collides with the daily log file names.');
        }
    }

    /**
     * Expression des noms de fichiers quotidiens et de rétention du canal
     * (Monolog RotatingFileHandler : « {filename}-{Y-m-d}.{ext} »).
     */
    public static function dailyLogPattern(string $logPath): string
    {
        $info = pathinfo($logPath);
        $extension = isset($info['extension']) ? '\.'.preg_quote($info['extension'], '/') : '';

        return '/^'.preg_quote($info['filename'], '/').'-[0-9]{4}-[0-9]{2}-[0-9]{2}'.$extension.'$/i';
    }

    private static function within(string $candidate, string $root): bool
    {
        return $candidate === $root || str_starts_with($candidate.'/', rtrim($root, '/').'/');
    }

    private static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^(/|[A-Za-z]:/)#', $path);
    }

    private static function hasRelativeSegment(string $path): bool
    {
        return (bool) preg_match('#(^|/)\.{1,2}(/|$)#', $path);
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return strlen($path) > 1 ? rtrim($path, '/') : $path;
    }

    /**
     * Forme de comparaison : séparateurs « / » ; insensible à la casse sous
     * Windows (développement local) uniquement.
     */
    private static function comparable(string $path): string
    {
        $path = self::normalize($path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
