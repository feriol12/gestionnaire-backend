<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BudgetCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Liste des budgets transformés
            'data' => BudgetResource::collection($this->collection),

            // Informations supplémentaires
            'meta' => [
                'total' => $this->collection->count(),
                'year_filter' => $request->get('year'),
                'month_filter' => $request->get('month'),
            ]
        ];
    }
}
