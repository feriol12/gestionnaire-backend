<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ExpenseCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => ExpenseResource::collection($this->collection),
            'meta' => [
                'total' => $this->collection->count(),
                'period' => $request->get('period', 'month')
            ]
        ];
    }
}