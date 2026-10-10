<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function mostBorrowed(Request $request)
    {
        if (!$request->filled('from') || !$request->filled('to')) {
            throw new BusinessException('VALIDATION_ERROR', 'Los parámetros from y to son obligatorios.', 422, [
                ['field' => 'from', 'issue' => 'requerido'],
                ['field' => 'to', 'issue' => 'requerido'],
            ]);
        }

        try {
            $from = Carbon::parse($request->query('from'))->startOfDay();
            $to = Carbon::parse($request->query('to'))->endOfDay();
        } catch (\Throwable $e) {
            throw new BusinessException('VALIDATION_ERROR', 'Formato de fecha inválido (use YYYY-MM-DD).', 422);
        }

        if ($to->lt($from)) {
            throw new BusinessException('INVALID_DATE_RANGE', 'La fecha de fin no puede ser anterior a la fecha de inicio.', 422, [
                ['field' => 'to', 'issue' => 'fecha de fin anterior a inicio']
            ]);
        }

        // Aggregate query strictly on frozen values in loan_item (ADR-004)
        $results = DB::table('library.loan_item')
            ->join('library.loan', 'library.loan_item.loan_id', '=', 'library.loan.id')
            ->whereBetween('library.loan.loaned_at', [$from, $to])
            ->select(
                'library.loan_item.book_id',
                'library.loan_item.book_title',
                'library.loan_item.category_name',
                DB::raw('COUNT(library.loan_item.id) as times_borrowed')
            )
            ->groupBy(
                'library.loan_item.book_id',
                'library.loan_item.book_title',
                'library.loan_item.category_name'
            )
            ->orderByDesc('times_borrowed')
            ->orderBy('library.loan_item.book_title', 'asc')
            ->get();

        $rows = $results->map(function ($r) {
            return [
                'book_id' => (string) $r->book_id,
                'book_title' => $r->book_title,
                'category_name' => $r->category_name,
                'times_borrowed' => (int) $r->times_borrowed,
            ];
        });

        return response()->json([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'rows' => $rows,
        ], 200);
    }
}
