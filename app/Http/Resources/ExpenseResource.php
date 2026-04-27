<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $categoryIcons = [
            'Nourriture' => '🍔',
            'Transport' => '🚕',
            'Factures' => '💡',
            'Loisirs' => '🎮',
            'Imprévu' => '⚠️'
        ];
        
        return [
            'id' => $this->id,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'formatted_amount' => number_format($this->amount, 2, ',', ' ') . ' €',
            'category' => $this->category,
            'category_icon' => $categoryIcons[$this->category] ?? '📌',
            'date' => $this->date,
            'formatted_date' => Carbon::parse($this->date)->format('d/m/Y'),
        ];
    }
}