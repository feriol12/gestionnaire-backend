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


}
