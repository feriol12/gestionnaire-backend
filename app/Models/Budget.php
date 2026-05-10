<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Budget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'month',
        'amount',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query): Builder
    {
        return $query->where('user_id', auth()->id());
    }

    public function scopeByYear(Builder $query, $year): Builder
    {
        return $query->whereYear('month', $year);
    }


    public function scopeByMonth(Builder $query, $month): Builder
    {
        return $query->whereMonth('month', $month);
    }

    public function scopeCurrentMonth(Builder $query): Builder
    {
        return $query->whereMonth('month', Carbon::now()->month)
            ->whereYear('month', Carbon::now()->year);
    }


    public function scopePreviousMonth(Builder $query): Builder
    {
        return $query->whereMonth('month', Carbon::now()->subMonth()->month)
            ->whereYear('month', Carbon::now()->subMonth()->year);
    }
}
