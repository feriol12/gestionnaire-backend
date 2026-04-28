<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Carbon\Carbon;

class CurrentMonthDate implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
          $date = Carbon::parse($value);
        $now = Carbon::now();
        
        $startOfMonth = $now->copy()->startOfMonth();  // 2026-04-01
        $today = $now->copy();                         // 2026-04-22
        
        // Vérifier : entre le 1er du mois ET aujourd'hui inclus
        if (!$date->between($startOfMonth, $today)) {
            $fail("La date doit être comprise entre le {$startOfMonth->format('d/m/Y')} et aujourd'hui ({$today->format('d/m/Y')}).");
        }
    }
}
