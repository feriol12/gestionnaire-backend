<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ExportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function export(Request $request)
    {
        try {
            $period = $request->get('period', 'month');

            $expenses = Expense::forUser()
                ->byPeriod($period)
                ->orderBy('date', 'desc')
                ->get();

            $total = $expenses->sum('amount');

            $html = '
            <html>
            <head>
                <style>
                    body { font-family: DejaVu Sans, sans-serif; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
                    th { background: #f0f0f0; }
                </style>
            </head>
            <body>
                <h2>Dépenses - ' . $period . '</h2>
                <p>Export du ' . Carbon::now()->format('d/m/Y H:i') . '</p>
                <table>
                    <thead>
                        <tr><th>Date</th><th>Description</th><th>Catégorie</th><th>Montant</th></tr>
                    </thead>
                    <tbody>';

            foreach ($expenses as $e) {
                $html .= '
                    <tr>
                        <td>' . $e->date . '</td>
                        <td>' . $e->description . '</td>
                        <td>' . $e->category . '</td>
                        <td style="text-align:right">' . number_format($e->amount, 2, ',', ' ') . ' €</td>
                    </tr>';
            }

            $html .= '
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3"><strong>Total</strong></td>
                            <td style="text-align:right"><strong>' . number_format($total, 2, ',', ' ') . ' €</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </body>
            </html>';

            $pdf = Pdf::loadHTML($html);
            return $pdf->download("depenses_{$period}.pdf");
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
