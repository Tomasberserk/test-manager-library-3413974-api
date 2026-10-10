<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\Book;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Loan::with(['items', 'member', 'loanedByUser']);

        if ($request->filled('member_id')) {
            $query->where('member_id', $request->query('member_id'));
        }

        if ($request->filled('from')) {
            $query->where('loaned_at', '>=', Carbon::parse($request->query('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('loaned_at', '<=', Carbon::parse($request->query('to'))->endOfDay());
        }

        $allLoans = $query->orderBy('loaned_at', 'desc')->get();

        // Filter by calculated status if requested
        if ($request->filled('status')) {
            $statusFilter = strtolower($request->query('status'));
            $allLoans = $allLoans->filter(fn($l) => $l->status === $statusFilter)->values();
        }

        $total = $allLoans->count();
        $pagedLoans = $allLoans->slice(($page - 1) * $size, $size)->values();

        $items = $pagedLoans->map(fn($loan) => $this->formatLoan($loan));

        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    public function show($id)
    {
        $loan = Loan::with(['items', 'member', 'loanedByUser'])->find($id);
        if (!$loan) {
            throw new BusinessException('NOT_FOUND', 'Préstamo no encontrado.', 404);
        }

        return response()->json($this->formatLoan($loan), 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'member_id' => 'required|uuid',
            'items' => 'required|array',
            'items.*.book_id' => 'required|uuid',
        ]);

        $memberId = $request->input('member_id');
        $rawItems = $request->input('items');

        // CA-06.1: 1 to 3 items
        if (count($rawItems) < 1 || count($rawItems) > 3) {
            throw new BusinessException('VALIDATION_ERROR', 'Un préstamo debe contener entre 1 y 3 libros.', 422, [
                ['field' => 'items', 'issue' => 'debe contener entre 1 y 3 líneas']
            ]);
        }

        $member = Member::find($memberId);
        if (!$member) {
            throw new BusinessException('NOT_FOUND', 'Socio no encontrado.', 404);
        }

        // CA-06.2: No duplicate book in same loan
        $bookIds = array_map(fn($it) => $it['book_id'], $rawItems);
        if (count($bookIds) !== count(array_unique($bookIds))) {
            throw new BusinessException('DUPLICATE_BOOK_IN_LOAN', 'No se puede repetir el mismo libro en el mismo préstamo.', 422, [
                ['field' => 'items', 'issue' => 'libro duplicado en la solicitud']
            ]);
        }

        $now = $request->filled('loaned_at') ? Carbon::parse($request->input('loaned_at'), 'UTC') : Carbon::now('UTC');

        // CA-06.5: Member with overdue items cannot borrow
        $hasOverdue = LoanItem::whereHas('loan', fn($q) => $q->where('member_id', $member->id))
            ->whereNull('returned_at')
            ->where('due_at', '<', $now)
            ->exists();

        if ($hasOverdue) {
            throw new BusinessException('MEMBER_HAS_OVERDUE', 'El socio tiene libros vencidos pendientes de devolución.', 409, [
                ['field' => 'member_id', 'issue' => 'socio con libros vencidos']
            ]);
        }

        // CA-06.4: Member limit of max 3 active unreturned books
        $activeLoansCount = LoanItem::whereHas('loan', fn($q) => $q->where('member_id', $member->id))
            ->whereNull('returned_at')
            ->count();

        $requestedCount = count($rawItems);
        if ($activeLoansCount + $requestedCount > 3) {
            throw new BusinessException('MEMBER_LIMIT_EXCEEDED', 'El socio no puede tener más de 3 libros sin devolver en total.', 422, [
                ['field' => 'items', 'issue' => "actuales sin devolver: {$activeLoansCount}, intentados: {$requestedCount}"]
            ]);
        }

        $authUser = $request->attributes->get('auth_user') ?: $request->user();

        // Execute atomic transaction with optimistic locking / pessimistic lock for update
        $loan = DB::transaction(function () use ($member, $rawItems, $bookIds, $now, $authUser) {
            // Sort book IDs to prevent deadlocks
            sort($bookIds);

            $books = Book::with('category')
                ->whereIn('id', $bookIds)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($rawItems as $idx => $it) {
                $bId = $it['book_id'];
                if (!isset($books[$bId])) {
                    throw new BusinessException('NOT_FOUND', "El libro {$bId} no existe o está dado de baja.", 404, [
                        ['field' => "items[{$idx}].book_id", 'issue' => 'libro no encontrado']
                    ]);
                }

                $book = $books[$bId];
                if ($book->available_copies < 1) {
                    throw new BusinessException('BOOK_NOT_AVAILABLE', "No quedan ejemplares disponibles de «{$book->title}».", 409, [
                        ['field' => "items[{$idx}].book_id", 'issue' => 'sin ejemplares']
                    ]);
                }
            }

            // Create Loan
            $newLoan = Loan::create([
                'member_id' => $member->id,
                'loaned_by_user_id' => $authUser->id,
                'loaned_at' => $now,
            ]);

            $dueAt = $now->copy()->addDays(14);

            // Deduct copies and freeze lines in loan_item
            foreach ($rawItems as $it) {
                $book = $books[$it['book_id']];
                $book->available_copies -= 1;
                $book->save();

                LoanItem::create([
                    'loan_id' => $newLoan->id,
                    'book_id' => $book->id,
                    'book_title' => $book->title,
                    'isbn' => $book->isbn,
                    'category_name' => $book->category->name,
                    'due_at' => $dueAt,
                    'returned_at' => null,
                ]);
            }

            return $newLoan;
        });

        $loan->load('items');

        return response()->json($this->formatLoan($loan), 201);
    }

    public function returnItem($loanId, $itemId)
    {
        $item = LoanItem::where('loan_id', $loanId)->where('id', $itemId)->first();
        if (!$item) {
            throw new BusinessException('NOT_FOUND', 'Línea de préstamo no encontrada.', 404);
        }

        // CA-07.2: Item already returned
        if ($item->returned_at !== null) {
            throw new BusinessException('ITEM_ALREADY_RETURNED', 'Esta línea de préstamo ya ha sido devuelta.', 409, [
                ['field' => 'item_id', 'issue' => 'ya devuelto']
            ]);
        }

        $now = Carbon::now('UTC');

        DB::transaction(function () use ($item, $now) {
            $item->returned_at = $now;
            $item->save();

            $book = Book::where('id', $item->book_id)->lockForUpdate()->first();
            if ($book) {
                $book->available_copies += 1;
                $book->save();
            }
        });

        return response()->json([
            'id' => (string) $item->id,
            'book_id' => (string) $item->book_id,
            'book_title' => $item->book_title,
            'isbn' => $item->isbn,
            'category_name' => $item->category_name,
            'due_at' => $item->due_at->toIso8601ZuluString(),
            'returned_at' => $item->returned_at->toIso8601ZuluString(),
        ], 200);
    }

    public function overdue()
    {
        $now = Carbon::now('UTC');

        $overdueItems = LoanItem::with('loan.member')
            ->whereNull('returned_at')
            ->where('due_at', '<', $now)
            ->get();

        $rows = $overdueItems->map(function ($item) use ($now) {
            $due = Carbon::parse($item->due_at, 'UTC');
            $daysLate = (int) max(0, $due->diffInDays($now));

            return [
                'loan_id' => (string) $item->loan_id,
                'item_id' => (string) $item->id,
                'book_title' => $item->book_title,
                'member_name' => $item->loan && $item->loan->member ? $item->loan->member->full_name : '',
                'due_at' => $due->toIso8601ZuluString(),
                'days_late' => $daysLate,
            ];
        })->sortByDesc('days_late')->values();

        return response()->json([
            'items' => $rows,
            'total' => $rows->count(),
        ], 200);
    }

    private function formatLoan(Loan $loan): array
    {
        $items = ($loan->relationLoaded('items') ? $loan->items : $loan->items()->get())->map(function ($it) {
            return [
                'id' => (string) $it->id,
                'book_id' => (string) $it->book_id,
                'book_title' => $it->book_title,
                'isbn' => $it->isbn,
                'category_name' => $it->category_name,
                'due_at' => $it->due_at ? Carbon::parse($it->due_at)->toIso8601ZuluString() : null,
                'returned_at' => $it->returned_at ? Carbon::parse($it->returned_at)->toIso8601ZuluString() : null,
            ];
        });

        return [
            'id' => (string) $loan->id,
            'member_id' => (string) $loan->member_id,
            'loaned_at' => Carbon::parse($loan->loaned_at)->toIso8601ZuluString(),
            'status' => $loan->status,
            'items' => $items,
        ];
    }
}
