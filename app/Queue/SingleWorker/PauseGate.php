<?php

namespace App\Queue\SingleWorker;

use JsonException;
use RuntimeException;

/*
 * LWS-BE-06C — garde de pause persistante du worker unique.
 *
 * Présence du fichier = file en pause. Ce n'est PAS l'autorité d'exclusion
 * (c'est le verrou noyau) : le worker lit cette garde UNIQUEMENT après avoir
 * acquis le verrou. Avec le drain (garde publiée AVANT la sonde du verrou),
 * cet ordre garantit qu'aucun worker ne démarre entre la preuve de quiescence
 * et la reprise explicite.
 *
 * État (V2, revue Codex B1) : SEULE une absence POSITIVEMENT établie vaut
 * « pas en pause » : le dossier parent a pu être listé ET le nom de la garde
 * n'y figure pas. Tout le reste est indéterminé et lève une exception
 * (l'appelant échoue fermé : code 74, aucun worker, jamais « reprise réussie ») :
 * dossier illisible ou disparu, métadonnées de la garde inaccessibles, lien
 * symbolique, type autre que fichier. Un booléen faux de file_exists() n'est
 * jamais pris pour une absence.
 *
 *  - Publication atomique : fichier temporaire dans le même dossier puis
 *    rename() ; jamais de fichier partiellement écrit.
 *  - Publication idempotente : une garde déjà présente n'est pas réécrite
 *    (son set_at d'origine est conservé).
 *  - Retrait : unlink(), puis vérification ; tout échec lève une exception
 *    (jamais rapporté comme un succès).
 *  - Le contenu (JSON) est informatif : seule la présence compte ; un contenu
 *    illisible laisse la file EN PAUSE.
 */
final class PauseGate
{
    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z$/';

    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return array{paused: bool, since: ?string}
     *
     * @throws RuntimeException si l'état ne peut pas être établi
     */
    public function state(): array
    {
        $directory = dirname($this->path);
        $name = basename($this->path);

        clearstatcache(true, $directory);
        clearstatcache(true, $this->path);

        $entries = @scandir($directory);

        if ($entries === false) {
            throw new RuntimeException('pause state indeterminate: pause directory cannot be listed');
        }

        if (! $this->listed($name, $entries)) {
            return ['paused' => false, 'since' => null];
        }

        if (is_link($this->path)) {
            throw new RuntimeException('pause path is a symbolic link');
        }

        $stat = @lstat($this->path);

        if ($stat === false) {
            throw new RuntimeException('pause state indeterminate: pause flag metadata unavailable');
        }

        if (($stat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('pause path is not a regular file');
        }

        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $since = is_array($data) && is_string($data['set_at'] ?? null) && preg_match(self::TIMESTAMP, $data['set_at']) ? $data['set_at'] : null;

        return ['paused' => true, 'since' => $since];
    }

    /**
     * Publie la garde. true = publiée maintenant ; false = déjà présente
     * (laissée intacte).
     *
     * @param  array<string, string>  $meta
     *
     * @throws RuntimeException si la publication échoue ou si l'état est indéterminé
     */
    public function set(array $meta): bool
    {
        if ($this->state()['paused']) {
            return false;
        }

        try {
            $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException) {
            throw new RuntimeException('cannot encode pause flag content');
        }

        $temporary = dirname($this->path).'/.'.basename($this->path).'.'.bin2hex(random_bytes(6)).'.tmp';

        if (@file_put_contents($temporary, $json) !== strlen($json)) {
            @unlink($temporary);

            throw new RuntimeException('cannot write pause flag (temporary file)');
        }

        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException('cannot publish pause flag (rename)');
        }

        if (! $this->state()['paused']) {
            throw new RuntimeException('pause flag not visible after publication');
        }

        return true;
    }

    /**
     * Retire la garde. true = retirée ; false = absence positivement établie.
     *
     * @throws RuntimeException si le retrait échoue ou si l'état est indéterminé
     */
    public function clear(): bool
    {
        if (! $this->state()['paused']) {
            return false;
        }

        if (! @unlink($this->path) || $this->state()['paused']) {
            throw new RuntimeException('cannot remove pause flag');
        }

        return true;
    }

    /**
     * @param  list<string>  $entries
     */
    private function listed(string $name, array $entries): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return in_array($name, $entries, true);
        }

        // Windows (développement local) : noms insensibles à la casse.
        return in_array(strtolower($name), array_map('strtolower', $entries), true);
    }
}
