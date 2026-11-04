<?php

namespace App\Queue\SingleWorker;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/*
 * LWS-BE-06C — journal opérationnel structuré du worker unique (canal
 * « queue_ops » UNIQUEMENT).
 *
 * Une ligne JSON par événement (fichiers quotidiens, 14 jours, 0600), dans la
 * racine privée. Le panneau LWS n'expose ni sortie ni code de retour : ce
 * journal fait foi pour les invocations ops:queue-*.
 *
 * Aucune écriture sur disque tant que TOUS les chemins opérationnels n'ont pas
 * été validés ensemble (frontières privée/publique, espaces de noms ; revue
 * Codex B2/B3) : sinon, repli STDERR seul.
 *
 * Champs : les champs communs ci-dessous plus ceux de ALLOWED_FIELDS ; toute
 * autre clé est ignorée (liste blanche appliquée ici, pas seulement par
 * discipline des appelants). Ce journal ne contient ni charge utile, ni message
 * d'exception, ni email, ni jeton, ni secret, ni environnement. Cette garantie
 * couvre CE canal seulement : le journal applicatif de Laravel (report()),
 * failed_jobs, la sortie Cron et les journaux tiers ne sont pas assainis ici.
 *
 * emit() ne lève JAMAIS d'exception : un échec d'écriture produit une seule
 * ligne sans donnée sensible sur STDERR (capturée par la redirection Cron) et
 * l'exécution continue. L'exclusion (verrou, garde, libération) ne dépend
 * jamais du journal. Une écriture peut toutefois attendre le verrou du
 * fichier journal (Monolog LOCK_EX) : « ne lève jamais » n'est pas une borne
 * de latence.
 */
final class OpsLog
{
    public const ALLOWED_FIELDS = [
        'lock_path', 'reason', 'stage', 'error', 'paused_since', 'options',
        'worker_status', 'stop_reason', 'jobs_processed',
        'job', 'job_id', 'attempts', 'exception_class',
        'exit_code', 'elapsed_ms', 'lock_released', 'waited_ms',
    ];

    private readonly string $runId;

    /** @var resource|null */
    private $fallback;

    private function __construct(
        private ?LoggerInterface $logger,
        private readonly string $command,
        private readonly string $context,
        private readonly ?string $lockPath,
        $fallback = null,
    ) {
        $this->runId = bin2hex(random_bytes(8));
        $this->fallback = $fallback;
    }

    /**
     * Journal du canal queue_ops si TOUS les chemins opérationnels sont
     * valides ensemble ; sinon repli STDERR seul, aucune écriture sur disque
     * (la commande refusera ensuite de démarrer : CONFIG_INVALID).
     *
     * @param  resource|null  $fallback  flux de repli (STDERR par défaut)
     */
    public static function make(string $command, string $context, $fallback = null): self
    {
        $logger = null;
        $lockPath = null;

        try {
            $paths = WorkerSettings::paths(config(), base_path());
            $lockPath = $paths['lock'];
            $logger = Log::build(array_merge((array) config('logging.channels.queue_ops'), ['path' => $paths['log']]));
        } catch (Throwable) {
            $logger = null;
        }

        return new self($logger, $command, $context, $lockPath, $fallback);
    }

    /**
     * Chemin du verrou validé (null si la configuration est invalide).
     */
    public function lockPath(): ?string
    {
        return $this->lockPath;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function emit(string $event, array $fields = []): void
    {
        try {
            if ($this->logger === null) {
                throw new RuntimeException('queue_ops log unavailable');
            }

            $this->logger->info($event, [
                'ts' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                'event' => $event,
                'run_id' => $this->runId,
                'command' => $this->command,
                'context' => $this->context,
                'pid' => getmypid(),
                'ppid' => function_exists('posix_getppid') ? posix_getppid() : null,
                'host' => gethostname() ?: null,
                'app_env' => app()->environment(),
            ] + array_intersect_key($fields, array_flip(self::ALLOWED_FIELDS)));
        } catch (Throwable) {
            $this->fallbackLine($event);
        }
    }

    /**
     * Ferme les gestionnaires (rotation/rétention appliquées à la fermeture).
     */
    public function close(): void
    {
        try {
            if ($this->logger !== null && method_exists($this->logger, 'getLogger')) {
                $monolog = $this->logger->getLogger();

                if (method_exists($monolog, 'close')) {
                    $monolog->close();
                }
            }
        } catch (Throwable) {
            // Fermeture best-effort : sans effet sur l'exclusion.
        }
    }

    private function fallbackLine(string $event): void
    {
        try {
            $stream = $this->fallback ?? (defined('STDERR') ? STDERR : null);

            if (is_resource($stream)) {
                @fwrite($stream, sprintf("queue-ops-log-failure event=%s run_id=%s\n", $event, $this->runId));
            }
        } catch (Throwable) {
            // Dernier recours : rien d'autre à faire, l'exécution continue.
        }
    }
}
