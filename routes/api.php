<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Health check endpoint (spec: GET /health)
Route::get('/health', fn() => response()->json(['status' => 'ok']));

Route::prefix('v1')->group(function () {
    Route::get('/health', fn() => response()->json(['status' => 'ok']));

    // Public auth
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Book cover serving (public or authenticated)
    Route::get('/books/{id}/cover', [BookController::class, 'getCover']);

    // Authenticated routes
    Route::middleware('jwt.auth')->group(function () {
        // Categories
        Route::get('/categories', [CategoryController::class, 'index']);

        // Books (search & detail available to any authenticated user)
        Route::get('/books', [BookController::class, 'index']);
        Route::get('/books/{id}', [BookController::class, 'show']);

        // Member management (admin & librarian)
        Route::middleware('jwt.auth:admin,librarian')->group(function () {
            Route::get('/members', [MemberController::class, 'index']);
            Route::get('/members/{id}', [MemberController::class, 'show']);
            Route::post('/members', [MemberController::class, 'store']);

            // Loans management
            Route::post('/loans', [LoanController::class, 'store']);
            Route::get('/loans', [LoanController::class, 'index']);
            Route::get('/loans/overdue', [LoanController::class, 'overdue']);
            Route::get('/loans/{id}', [LoanController::class, 'show']);
            Route::post('/loans/{loan_id}/items/{item_id}/return', [LoanController::class, 'returnItem']);
        });

        // Admin-only operations
        Route::middleware('jwt.auth:admin')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);

            Route::post('/books', [BookController::class, 'store']);
            Route::put('/books/{id}', [BookController::class, 'update']);
            Route::delete('/books/{id}', [BookController::class, 'destroy']);
            Route::put('/books/{id}/cover', [BookController::class, 'uploadCover']);
            Route::delete('/books/{id}/cover', [BookController::class, 'deleteCover']);

            Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
        });
    });
});
