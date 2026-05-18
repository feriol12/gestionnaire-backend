<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BudgetExportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function export(Request $request)
    {
        try {

            $period = $request->get('period', 'month');

            $query = Budget::forUser();

            // Filtre période
            if ($period === 'month') {

                $query->currentMonth();

            } elseif ($period === 'year') {

                $query->byYear(now()->year);
            }

            $budgets = $query
                ->orderBy('month', 'desc')
                ->get();

            $total = $budgets->sum('amount');

            $html = '
            <html>
            <head>
                <style>
                    body {
                        font-family: DejaVu Sans, sans-serif;
                    }

                    table {
                        width: 100%;
                        border-collapse: collapse;
                    }

                    th, td {
                        border: 1px solid #ccc;
                        padding: 8px;
                        text-align: left;
                    }

                    th {
                        background: #f0f0f0;
                    }
                </style>
            </head>

            <body>

                <h2>Budgets - ' . $period . '</h2>

                <p>
                    Export du ' . Carbon::now()->format('d/m/Y H:i') . '
                </p>

                <table>

                    <thead>
                        <tr>
                            <th>Mois</th>
                            <th>Montant</th>
                        </tr>
                    </thead>

                    <tbody>';

            foreach ($budgets as $budget) {

                $html .= '
                    <tr>
                        <td>' . Carbon::parse($budget->month)->format('m/Y') . '</td>

                        <td style="text-align:right">
                            ' . number_format($budget->amount, 0, ',', ' ') . ' FCFA
                        </td>
                    </tr>';
            }

            $html .= '
                    </tbody>

                    <tfoot>
                        <tr>
                            <td>
                                <strong>Total</strong>
                            </td>

                            <td style="text-align:right">
                                <strong>
                                    ' . number_format($total, 0, ',', ' ') . ' FCFA
                                </strong>
                            </td>
                        </tr>
                    </tfoot>

                </table>

            </body>
            </html>';

            $pdf = Pdf::loadHTML($html);

            return $pdf->download("budgets_{$period}.pdf");

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }
}
