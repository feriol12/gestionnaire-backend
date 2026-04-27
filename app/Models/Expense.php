<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Expense extends Model
{
    use HasFactory;

     protected $fillable = [
        'user_id',
        'description',
        'amount',
        'category',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

       // Relation avec User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scope pour l'utilisateur connecté
    public function scopeForUser(Builder $query): Builder
    {
        return $query->where('user_id', auth()->id());
    }

    // Scope par période
    public function scopeByPeriod(Builder $query, string $period): Builder
    {
        return match($period) {
            'today' => $query->whereDate('date', Carbon::today()),
            'week' => $query->whereBetween('date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
            'month' => $query->whereMonth('date', Carbon::now()->month)
                              ->whereYear('date', Carbon::now()->year),
            'year' => $query->whereYear('date', Carbon::now()->year),
            default => $query,
        };
    }

    // Scope pour le mois précédent (comparaison)
    public function scopePreviousMonth(Builder $query): Builder
    {
        return $query->whereMonth('date', Carbon::now()->subMonth()->month)
                     ->whereYear('date', Carbon::now()->subMonth()->year);
    }
}
