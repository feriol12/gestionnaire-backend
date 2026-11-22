<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BE-06 — liste blanche stricte du contrat API :
 *
 *   liste  : id, nom, email, administrative_status, auth_version, created_at
 *   détail : les mêmes champs + suspended_at
 *
 * Mapping : la clé JSON « nom » est alimentée par la colonne User.name.
 *
 * BE-03 livre les colonnes administrative_status, auth_version et
 * suspended_at. L'émission de chaque clé reste pilotée par hasColumn()
 * (colonne absente ⇒ clé omise, jamais de valeur inventée).
 * suspended_at n'appartient qu'à la FICHE (GOV-05 V1.1 §3, OpenCode F-02) :
 * il n'est émis que pour une ressource marquée detail().
 *
 * password, remember_token, suspension_reason (motif interne) et toute
 * donnée financière ne sont jamais sérialisés — liste blanche, jamais
 * une liste noire.
 *
 * @property \App\Models\User $resource
 */
class AdminUserResource extends JsonResource
{
    /** Fiche (GET /users/{id}) plutôt que ligne de liste. */
    private bool $detail = false;

    /** Marque la ressource comme fiche : ajoute suspended_at (GOV-05 V1.1 §3). */
    public function detail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            // Contrat : la clé JSON est « nom », colonne source User.name.
            'nom' => $this->name,
            'email' => $this->email,
            // Instance datetime (cast Laravel natif des timestamps).
            'created_at' => $this->created_at?->toISOString(),
        ];

        // Champs du contrat portés par BE-03 : émis selon hasColumn()
        // (colonne absente ⇒ clé omise ; colonne présente ⇒ clé émise,
        // y compris avec la valeur null explicite).
        if ($this->hasColumn('administrative_status')) {
            $data['administrative_status'] = $this->administrative_status;
        }

        if ($this->hasColumn('auth_version')) {
            $data['auth_version'] = $this->auth_version;
        }

        if ($this->detail && $this->hasColumn('suspended_at')) {
            $suspendedAt = $this->suspended_at;
            // Cast datetime sur User depuis BE-03 : ISO 8601 ; une valeur brute
            // (string ou null) est reprise telle quelle, sans invention.
            $data['suspended_at'] = $suspendedAt instanceof \DateTimeInterface
                ? $suspendedAt->format(DATE_RFC3339_EXTENDED)
                : $suspendedAt;
        }

        return $data;
    }

    /**
     * Détection exacte de la présence de la colonne côté modèle.
     *
     * Eloquent::offsetExists() / isset($model->champ) confondent
     * « attribut présent mais NULL » et « attribut absent » (implémentation
     * `! is_null($this->{$offset})`) : les deux cas doivent être distingués
     * — un attribut NULL est exposé comme null JSON, un attribut
     * absent (colonne inexistante) est omis. On interroge donc les
     * attributs bruts du modèle, qui reflètent exactement les colonnes
     * retournées par la requête SQL.
     */
    private function hasColumn(string $field): bool
    {
        return array_key_exists($field, $this->resource->getAttributes());
    }
}
