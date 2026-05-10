<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use Illuminate\Http\Request;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Http\Resources\BudgetCollection;

class BudgetController extends Controller
{
    // Liste des budgets de l'utilisateur connecté
   public function index(Request $request)
{
    $query = Budget::forUser();

    // ✅ La correction est ICI
        if ($request->filled('year')) {
            $query->byYear($request->year);  // Maintenant ça fonctionne !
        }

        if ($request->filled('month')) {
            $query->byMonth($request->month); // Maintenant ça fonctionne !
        }

    $budgets = $query->orderBy('month', 'desc')->get();

    // return response()->json($budgets);
    return new BudgetCollection($budgets);
}
    // Création budget
    public function store(StoreBudgetRequest $request)
    {
        $budget = Budget::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Budget créé avec succès',
            'budget' => new BudgetResource($budget),
        ], 201);
    }

    // Afficher un budget
    public function show(Budget $budget)
    {
        // sécurité
        if ($budget->user_id !== auth()->id()) {
            return response()->json([
                'message' => 'Non autorisé'
            ], 403);
        }

        // return response()->json($budget);
        return new BudgetResource($budget);
    }

    // Modifier budget
    public function update(UpdateBudgetRequest $request, Budget $budget)
    {
        if ($budget->user_id !== auth()->id()) {
            return response()->json([
                'message' => 'Non autorisé'
            ], 403);
        }

        $budget->update($request->validated());

        return response()->json([
            'message' => 'Budget modifié avec succès',
            'budget' => new BudgetResource($budget),
        ]);
    }

    // Supprimer budget
    public function destroy(Budget $budget)
    {
        if ($budget->user_id !== auth()->id()) {
            return response()->json([
                'message' => 'Non autorisé'
            ], 403);
        }

        $budget->delete();

        return response()->json([
            'message' => 'Budget supprimé avec succès'
        ]);
    }

//metghode pour le résumé des budgets
    public function summary()
{
    return response()->json([
        'current_month_total' => Budget::forUser()
            ->currentMonth()
            ->sum('amount'),

        'last_month_total' => Budget::forUser()
            ->previousMonth()
            ->sum('amount'),

        'year_total' => Budget::forUser()
            ->byYear(now()->year)
            ->sum('amount'),
    ]);
}
}
