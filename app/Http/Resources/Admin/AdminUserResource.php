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
 * POINT IMPORTANT BE-03 : les colonnes administrative_status, auth_version
 * (côté utilisateur) et suspended_at n'existent pas encore sur `users`.
 * Tant que la migration BE-03 n'est pas livrée, ces trois clés sont
 * omises — aucune valeur n'est inventée et la réponse NE SATISFAIT PAS
 * encore le contrat complet. L'émission de chaque clé est pilotée par
 * hasColumn(), de sorte qu'aucun changement ne soit nécessaire ici
 * lorsque BE-03 livrera les colonnes.
 *
 * password, remember_token, suspension_reason (motif interne) et toute
 * donnée financière ne sont jamais sérialisés — liste blanche, jamais
 * une liste noire.
 *
 * @property \App\Models\User $resource
 */
class AdminUserResource extends JsonResource
{
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

        if ($this->hasColumn('suspended_at')) {
            $suspendedAt = $this->suspended_at;
            // Selon la migration BE-03, la colonne sera datetime (cast) ou non :
            // on sérialise en ISO 8601 uniquement si l'instance est déjà une
            // date, sinon la valeur brute (string ou null) est reprise telle
            // quelle — aucune transformation approximative, aucune invention.
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
