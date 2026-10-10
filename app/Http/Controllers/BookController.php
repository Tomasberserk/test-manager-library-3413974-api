<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\Book;
use App\Models\Category;
use App\Models\LoanItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BookController extends Controller
{
    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Book::with('category')->whereNull('deleted_at');

        if ($request->filled('q')) {
            $q = trim($request->query('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'ilike', "%{$q}%")
                    ->orWhere('author', 'ilike', "%{$q}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        $total = $query->count();
        $books = $query->orderBy('title', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $books->map(fn($book) => $this->formatBook($book));

        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    public function show($id)
    {
        $book = Book::with('category')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$book) {
            throw new BusinessException('NOT_FOUND', 'Libro no encontrado o dado de baja.', 404);
        }

        return response()->json($this->formatBook($book), 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:250',
            'author' => 'required|string|max:200',
            'isbn' => 'required|string|size:13|regex:/^[0-9]{13}$/',
            'category_id' => 'required|uuid',
            'total_copies' => 'required|integer|min:1',
        ]);

        $isbn = trim($request->input('isbn'));

        // Check active ISBN uniqueness (CA-03.2)
        $existing = Book::whereNull('deleted_at')->where('isbn', $isbn)->first();
        if ($existing) {
            throw new BusinessException('ISBN_TAKEN', "El ISBN '{$isbn}' ya está registrado en un libro activo.", 409, [
                ['field' => 'isbn', 'issue' => 'ISBN ya registrado']
            ]);
        }

        $category = Category::find($request->input('category_id'));
        if (!$category) {
            throw new BusinessException('VALIDATION_ERROR', 'La categoría especificada no existe.', 422, [
                ['field' => 'category_id', 'issue' => 'categoría inexistente']
            ]);
        }

        $totalCopies = (int) $request->input('total_copies');

        $book = Book::create([
            'title' => trim($request->input('title')),
            'author' => trim($request->input('author')),
            'isbn' => $isbn,
            'category_id' => $category->id,
            'total_copies' => $totalCopies,
            'available_copies' => $totalCopies,
            'cover_key' => null,
        ]);

        $book->load('category');

        return response()->json($this->formatBook($book), 201);
    }

    public function update(Request $request, $id)
    {
        $book = Book::with('category')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$book) {
            throw new BusinessException('NOT_FOUND', 'Libro no encontrado.', 404);
        }

        $request->validate([
            'title' => 'required|string|max:250',
            'author' => 'required|string|max:200',
            'category_id' => 'required|uuid',
            'total_copies' => 'required|integer|min:1',
        ]);

        $category = Category::find($request->input('category_id'));
        if (!$category) {
            throw new BusinessException('VALIDATION_ERROR', 'La categoría especificada no existe.', 422);
        }

        $newTotal = (int) $request->input('total_copies');
        $oldTotal = (int) $book->total_copies;
        $diff = $newTotal - $oldTotal;
        $newAvailable = $book->available_copies + $diff;

        if ($newAvailable < 0) {
            throw new BusinessException('VALIDATION_ERROR', 'No se puede reducir el total de copias por debajo de los ejemplares prestados.', 422, [
                ['field' => 'total_copies', 'issue' => 'disponibles quedarían en negativo']
            ]);
        }

        $book->title = trim($request->input('title'));
        $book->author = trim($request->input('author'));
        $book->category_id = $category->id;
        $book->total_copies = $newTotal;
        $book->available_copies = $newAvailable;
        $book->save();

        $book->load('category');

        return response()->json($this->formatBook($book), 200);
    }

    public function destroy($id)
    {
        $book = Book::whereNull('deleted_at')->where('id', $id)->first();
        if (!$book) {
            throw new BusinessException('NOT_FOUND', 'Libro no encontrado.', 404);
        }

        // Check if book has open loans (CA-03.5)
        $hasOpenLoans = LoanItem::where('book_id', $id)
            ->whereNull('returned_at')
            ->exists();

        if ($hasOpenLoans) {
            throw new BusinessException('BOOK_HAS_OPEN_LOANS', 'No se puede dar de baja un libro con préstamos abiertos.', 409, [
                ['field' => 'id', 'issue' => 'tiene préstamos sin devolver']
            ]);
        }

        $book->delete(); // Soft delete sets deleted_at = now()

        return response()->noContent();
    }

    public function uploadCover(Request $request, $id)
    {
        $book = Book::whereNull('deleted_at')->where('id', $id)->first();
        if (!$book) {
            throw new BusinessException('NOT_FOUND', 'Libro no encontrado.', 404);
        }

        if (!$request->hasFile('file')) {
            throw new BusinessException('VALIDATION_ERROR', 'No se ha proporcionado el archivo de portada.', 422, [
                ['field' => 'file', 'issue' => 'archivo requerido']
            ]);
        }

        $file = $request->file('file');

        // CA-03.4 & §4: JPG/PNG, max 2MB (2048 KB), check content type
        $mime = $file->getMimeType();
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new BusinessException('VALIDATION_ERROR', 'La portada debe ser formato JPG o PNG.', 422, [
                ['field' => 'file', 'issue' => 'formato no permitido']
            ]);
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            throw new BusinessException('VALIDATION_ERROR', 'La portada no puede superar los 2 MB.', 422, [
                ['field' => 'file', 'issue' => 'tamaño mayor a 2 MB']
            ]);
        }

        $coversDir = storage_path('app/covers');
        if (!File::exists($coversDir)) {
            File::makeDirectory($coversDir, 0755, true);
        }

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $opaqueKey = Str::uuid()->toString() . '.' . $extension;
        $file->move($coversDir, $opaqueKey);

        // Delete old cover if existed
        if ($book->cover_key && File::exists($coversDir . '/' . $book->cover_key)) {
            File::delete($coversDir . '/' . $book->cover_key);
        }

        $book->cover_key = $opaqueKey;
        $book->save();

        return response()->json([
            'cover_key' => $book->cover_key,
        ], 200);
    }

    public function deleteCover($id)
    {
        $book = Book::whereNull('deleted_at')->where('id', $id)->first();
        if (!$book) {
            throw new BusinessException('NOT_FOUND', 'Libro no encontrado.', 404);
        }

        $oldKey = $book->cover_key;
        $book->cover_key = null;
        $book->save();

        if ($oldKey) {
            $coversDir = storage_path('app/covers');
            $path = $coversDir . '/' . $oldKey;
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        return response()->noContent();
    }

    public function getCover($id)
    {
        $book = Book::whereNull('deleted_at')->where('id', $id)->first();
        if (!$book || !$book->cover_key) {
            throw new BusinessException('NOT_FOUND', 'Portada no encontrada.', 404);
        }

        $path = storage_path('app/covers/' . $book->cover_key);
        if (!File::exists($path)) {
            throw new BusinessException('NOT_FOUND', 'Archivo de portada no encontrado.', 404);
        }

        return response()->file($path);
    }

    private function formatBook(Book $book): array
    {
        return [
            'id' => (string) $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'category' => [
                'id' => (string) $book->category->id,
                'name' => $book->category->name,
            ],
            'total_copies' => (int) $book->total_copies,
            'available_copies' => (int) $book->available_copies,
            'cover_key' => $book->cover_key ?: null,
        ];
    }
}
