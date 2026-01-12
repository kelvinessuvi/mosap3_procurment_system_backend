<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\QuotationRequestController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated. Please login.'], 401);
})->name('login');

// Public Quotation Routes (Token based)
Route::get('/quotation/{token}', [\App\Http\Controllers\PublicQuotationController::class, 'show']);
Route::post('/quotation/{token}/submit', [\App\Http\Controllers\PublicQuotationController::class, 'submit']);
Route::post('/quotation/{token}/decline', [\App\Http\Controllers\PublicQuotationController::class, 'decline']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Categories (Viewable by all auth users)
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{category}', [CategoryController::class, 'show']);

    // Suppliers (Manageable by Admin and Technicians)
    Route::apiResource('suppliers', SupplierController::class);

    // Quotation Requests
    Route::apiResource('quotation-requests', QuotationRequestController::class);
    Route::post('quotation-requests/{quotationRequest}/send', [QuotationRequestController::class, 'send']);
    Route::post('quotation-requests/{quotationRequest}/cancel', [QuotationRequestController::class, 'cancel']);

    // Admin only routes
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('users', UserController::class);
        
        // Manage Categories
        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

        // Evaluation & Negotiation
        Route::apiResource('quotation-responses', \App\Http\Controllers\QuotationResponseController::class)->only(['index', 'show']);
        Route::post('quotation-responses/{quotationResponse}/approve', [\App\Http\Controllers\QuotationResponseController::class, 'approve']);
        Route::post('quotation-responses/{quotationResponse}/reject', [\App\Http\Controllers\QuotationResponseController::class, 'reject']);
        Route::post('quotation-responses/{quotationResponse}/request-revision', [\App\Http\Controllers\QuotationResponseController::class, 'requestRevision']);
        Route::post('quotation-responses/{quotationResponse}/create-acquisition', [\App\Http\Controllers\QuotationResponseController::class, 'createAcquisition']);
        
        // Dashboard
        Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index']);
        
        // Evaluations
        Route::get('supplier-evaluations', [\App\Http\Controllers\EvaluationController::class, 'index']);
        Route::get('suppliers/{id}/evaluation', [\App\Http\Controllers\EvaluationController::class, 'show']);
        Route::post('suppliers/{id}/evaluation/recalculate', [\App\Http\Controllers\EvaluationController::class, 'recalculate']);
        Route::post('supplier-evaluations/recalculate-all', [\App\Http\Controllers\EvaluationController::class, 'recalculateAll']);
    });
});
