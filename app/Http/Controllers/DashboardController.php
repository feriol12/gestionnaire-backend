<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Expense;

class DashboardController extends Controller
{
    //
    public function categories(Request $request)
    {
        $expenses = Expense::forUser()
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->get();

        $total = (float) $expenses->sum('amount');

        $categories = $expenses
            ->groupBy('category')
            ->map(function ($items, $category) use ($total) {

                $montant =(float) $items->sum('amount');

                return [
                    'nom' => $category,
                    'montant' => $montant,
                    'pourcentage' => $total > 0
                        ? round(($montant / $total) * 100, 2)
                        : 0,
                ];
            })
            ->values();

        return response()->json([
            'data' => $categories
        ]);
    }

    public function evolution()
{
    $months = [
        1 => 'Jan',
        2 => 'Fév',
        3 => 'Mar',
        4 => 'Avr',
        5 => 'Mai',
        6 => 'Juin',
        7 => 'Juil',
        8 => 'Août',
        9 => 'Sep',
        10 => 'Oct',
        11 => 'Nov',
        12 => 'Déc',
    ];

    $data = [];

    foreach ($months as $monthNumber => $label) {

        $montant = Expense::forUser()
            ->whereMonth('date', $monthNumber)
            ->whereYear('date', now()->year)
            ->sum('amount');

        $data[] = [
            'label' => $label,
            'montant' => (float) $montant
        ];
    }

    return response()->json([
        'data' => $data
    ]);
}



public function recentTransactions()
{
    $expenses = Expense::forUser()
        ->orderBy('date', 'desc')
        ->limit(5)
        ->get();

    return response()->json([
        'data' => $expenses->map(function ($e) {
            return [
                'id' => $e->id,
                'description' => $e->description,
                'amount' => $e->amount,
                'category' => $e->category,
                'date' => $e->date->format('Y-m-d'), // 👈 propre ici
            ];
        })
    ]);
}


}
