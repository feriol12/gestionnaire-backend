<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class BudgetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Date brute
            'month' => $this->month,

            // Date formatée
            'formatted_month' => Carbon::parse($this->month)->format('F Y'),

            // Montant brut
            'amount' => (float) $this->amount,

            // Montant formaté
            'formatted_amount' => number_format($this->amount, 0, ',', ' ') . ' FCFA',
        ];
    }
}
