<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\ExpenseCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Rules\CurrentMonthDate;      


class ExpenseController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // Liste des dépenses (Collection)
    public function index(Request $request)
    {
        $period = $request->get('period', 'month');
        
        $expenses = Expense::forUser()
            ->byPeriod($period)
            ->orderBy('date', 'desc')
            ->get();

        return new ExpenseCollection($expenses);
    }

    // Ajouter une dépense
    public function store(Request $request)
    {
       $validator = Validator::make($request->all(), [
        'description' => 'required|string|max:255',
        'amount' => 'required|numeric|min:0.01',
        'category' => 'required|in:Nourriture,Transport,Factures,Loisirs,Imprévu',
        'date' => ['required', 'date', new CurrentMonthDate()], // ← ICI la nouvelle règle
    ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $expense = Expense::create([
            'user_id' => auth()->id(),
            'description' => $request->description,
            'amount' => $request->amount,
            'category' => $request->category,
            'date' => $request->date,
        ]);

        return new ExpenseResource($expense);
    }

    // Afficher une dépense spécifique
    public function show($id)
    {
        $expense = Expense::forUser()->find($id);

        if (!$expense) {
            return response()->json(['message' => 'Dépense non trouvée'], 404);
        }

        return new ExpenseResource($expense);
    }

    // Modifier une dépense
    public function update(Request $request, $id)
    {
        $expense = Expense::forUser()->find($id);

        if (!$expense) {
            return response()->json(['message' => 'Dépense non trouvée'], 404);
        }

          $validator = Validator::make($request->all(), [
        'description' => 'sometimes|string|max:255',
        'amount' => 'sometimes|numeric|min:0.01',
        'category' => 'sometimes|in:Nourriture,Transport,Factures,Loisirs,Imprévu',
        'date' => ['sometimes', 'date', new CurrentMonthDate()], // ← ICI aussi
    ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $expense->update($request->only(['description', 'amount', 'category', 'date']));

        return new ExpenseResource($expense);
    }

    // Supprimer une dépense
    public function destroy($id)
    {
        $expense = Expense::forUser()->find($id);

        if (!$expense) {
            return response()->json(['message' => 'Dépense non trouvée'], 404);
        }

        $expense->delete();

        return response()->json(['message' => 'Dépense supprimée avec succès']);
    }

    // Totaux pour le dashboard
    public function summary()
    {
        return response()->json([
            'today' => Expense::forUser()->byPeriod('today')->sum('amount'),
            'this_month' => Expense::forUser()->byPeriod('month')->sum('amount'),
            'last_month' => Expense::forUser()->previousMonth()->sum('amount'),
            'this_year' => Expense::forUser()->byPeriod('year')->sum('amount'),
        ]);
    }
}