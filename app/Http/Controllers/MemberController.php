<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\LoanItem;
use App\Models\Member;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Member::query();

        if ($request->filled('q')) {
            $q = trim($request->query('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('full_name', 'ilike', "%{$q}%")
                    ->orWhere('document_number', 'ilike', "%{$q}%");
            });
        }

        $total = $query->count();
        $members = $query->orderBy('full_name', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $members->map(function ($m) {
            $activeLoans = LoanItem::whereHas('loan', fn($q) => $q->where('member_id', $m->id))
                ->whereNull('returned_at')
                ->count();

            return [
                'id' => (string) $m->id,
                'full_name' => $m->full_name,
                'document_number' => $m->document_number,
                'active_loans' => $activeLoans,
            ];
        });

        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    public function show($id)
    {
        $member = Member::find($id);
        if (!$member) {
            throw new BusinessException('NOT_FOUND', 'Socio no encontrado.', 404);
        }

        $activeLoans = LoanItem::whereHas('loan', fn($q) => $q->where('member_id', $id))
            ->whereNull('returned_at')
            ->count();

        return response()->json([
            'id' => (string) $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
            'active_loans' => $activeLoans,
        ], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'full_name' => 'required|string|max:200',
            'document_number' => 'required|string|max:30',
        ]);

        $doc = trim($request->input('document_number'));

        if (Member::where('document_number', $doc)->exists()) {
            throw new BusinessException('DOCUMENT_TAKEN', "El documento '{$doc}' ya está registrado.", 409, [
                ['field' => 'document_number', 'issue' => 'documento ya existe']
            ]);
        }

        $member = Member::create([
            'full_name' => trim($request->input('full_name')),
            'document_number' => $doc,
        ]);

        return response()->json([
            'id' => (string) $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
        ], 201);
    }
}
