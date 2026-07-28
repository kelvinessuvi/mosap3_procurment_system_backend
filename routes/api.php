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
Route::get('/quotation/{token}/attachments/{index}', [\App\Http\Controllers\PublicQuotationController::class, 'downloadAttachment']);

// Public Supplier Registration Routes (Token based)
Route::post('/supplier/register/{token}', [\App\Http\Controllers\PublicRegistrationController::class, 'register']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::get('/user/permissions', [UserController::class, 'myPermissions']);

    // Notifications
    Route::middleware('menu:notifications,read')->group(function () {
        Route::get('notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [\App\Http\Controllers\NotificationController::class, 'unreadCount']);
        Route::post('notifications/mark-all-read', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
        Route::get('notifications/{notification}', [\App\Http\Controllers\NotificationController::class, 'show']);
        Route::post('notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->middleware('menu:notifications,write');
        Route::delete('notifications/{notification}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->middleware('menu:notifications,write');
    });

    // Categories (read-only for auth users)
    Route::middleware('menu:categories,read')->group(function () {
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('categories/{category}', [CategoryController::class, 'show']);
    });

    // Products Catalog
    Route::middleware('menu:products,read')->group(function () {
        Route::get('products/{id}/analytics', [\App\Http\Controllers\ProductController::class, 'priceAnalytics']);
        Route::get('products', [\App\Http\Controllers\ProductController::class, 'index']);
        Route::get('products/{product}', [\App\Http\Controllers\ProductController::class, 'show']);
        Route::post('products', [\App\Http\Controllers\ProductController::class, 'store'])->middleware('menu:products,write');
        Route::put('products/{product}', [\App\Http\Controllers\ProductController::class, 'update'])->middleware('menu:products,write');
        Route::delete('products/{product}', [\App\Http\Controllers\ProductController::class, 'destroy'])->middleware('menu:products,write');
    });

    // Acquisitions & Reports
    Route::middleware('menu:acquisitions,read')->group(function () {
        Route::get('acquisitions', [\App\Http\Controllers\AcquisitionController::class, 'index']);
        Route::get('acquisitions/stats/products', [\App\Http\Controllers\AcquisitionController::class, 'productStats']);
        Route::post('acquisitions/{acquisition}/confirm-delivery', [\App\Http\Controllers\AcquisitionController::class, 'confirmDelivery'])->middleware('menu:acquisitions,write');
        Route::delete('acquisitions/{acquisition}', [\App\Http\Controllers\AcquisitionController::class, 'destroy'])->middleware('menu:acquisitions,write');
        Route::get('reports/summary', [\App\Http\Controllers\ReportsController::class, 'index']);
        Route::get('suppliers/{id}/acquisitions', [\App\Http\Controllers\AcquisitionController::class, 'supplierHistory']);
    });

    // Suppliers
    Route::middleware('menu:suppliers,read')->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index']);
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
        Route::get('suppliers/{supplier}/classification', [SupplierController::class, 'classification']);
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('menu:suppliers,write');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('menu:suppliers,write');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('menu:suppliers,write');
        Route::post('suppliers/invite', [SupplierController::class, 'invite'])->middleware('menu:suppliers,write');
        Route::post('suppliers/{supplier}/approve', [SupplierController::class, 'approve'])->middleware('menu:suppliers,write');
    });

    // Document routes (authenticated users can view documents)
    Route::middleware('menu:documents,read')->group(function () {
        Route::get('suppliers/{supplier}/documents/{documentType}', [\App\Http\Controllers\DocumentController::class, 'supplierDocument']);
        Route::get('quotation-responses/{quotationResponse}/document', [\App\Http\Controllers\DocumentController::class, 'proposalDocument']);
    });

    // Quotation Requests
    Route::middleware('menu:quotation-requests,read')->group(function () {
        Route::get('quotation-requests', [QuotationRequestController::class, 'index']);
        Route::get('quotation-requests/{quotationRequest}', [QuotationRequestController::class, 'show']);
        Route::post('quotation-requests', [QuotationRequestController::class, 'store'])->middleware('menu:quotation-requests,write');
        Route::put('quotation-requests/{quotationRequest}', [QuotationRequestController::class, 'update'])->middleware('menu:quotation-requests,write');
        Route::delete('quotation-requests/{quotationRequest}', [QuotationRequestController::class, 'destroy'])->middleware('menu:quotation-requests,write');
        Route::post('quotation-requests/{quotationRequest}/send', [QuotationRequestController::class, 'send'])->middleware('menu:quotation-requests,write');
        Route::post('quotation-requests/{quotationRequest}/cancel', [QuotationRequestController::class, 'cancel'])->middleware('menu:quotation-requests,write');
    });

    // Admin only routes
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('users', UserController::class);

        // Manage Categories
        Route::post('categories', [CategoryController::class, 'store'])->middleware('menu:categories,write');
        Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('menu:categories,write');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('menu:categories,write');

        // Evaluation & Negotiation
        Route::middleware('menu:supplier-evaluations,read')->group(function () {
            Route::apiResource('quotation-responses', \App\Http\Controllers\QuotationResponseController::class)->only(['index', 'show']);
            Route::post('quotation-responses/{quotationResponse}/approve', [\App\Http\Controllers\QuotationResponseController::class, 'approve'])->middleware('menu:supplier-evaluations,write');
            Route::post('quotation-responses/{quotationResponse}/reject', [\App\Http\Controllers\QuotationResponseController::class, 'reject'])->middleware('menu:supplier-evaluations,write');
            Route::post('quotation-responses/{quotationResponse}/request-revision', [\App\Http\Controllers\QuotationResponseController::class, 'requestRevision'])->middleware('menu:supplier-evaluations,write');
            Route::post('quotation-responses/{quotationResponse}/create-acquisition', [\App\Http\Controllers\QuotationResponseController::class, 'createAcquisition'])->middleware('menu:supplier-evaluations,write');

            // Dashboard
            Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index']);

            // Evaluations
            Route::get('supplier-evaluations', [\App\Http\Controllers\EvaluationController::class, 'index']);
            Route::get('suppliers/{id}/evaluation', [\App\Http\Controllers\EvaluationController::class, 'show']);
            Route::post('suppliers/{id}/evaluation/recalculate', [\App\Http\Controllers\EvaluationController::class, 'recalculate'])->middleware('menu:supplier-evaluations,write');
            Route::post('supplier-evaluations/recalculate-all', [\App\Http\Controllers\EvaluationController::class, 'recalculateAll'])->middleware('menu:supplier-evaluations,write');

            // Audit Logs
            Route::get('audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index'])->middleware('menu:audit-logs,read');
            Route::get('audit-logs/{auditLog}', [\App\Http\Controllers\AuditLogController::class, 'show'])->middleware('menu:audit-logs,read');

            // Deletion Requests Management
            Route::get('deletion-requests', [\App\Http\Controllers\DeletionRequestController::class, 'index'])->middleware('menu:deletion-requests,read');
            Route::get('deletion-requests/{deletionRequest}', [\App\Http\Controllers\DeletionRequestController::class, 'show'])->middleware('menu:deletion-requests,read');
            Route::post('deletion-requests/{deletionRequest}/approve', [\App\Http\Controllers\DeletionRequestController::class, 'approve'])->middleware('menu:deletion-requests,write');
            Route::post('deletion-requests/{deletionRequest}/reject', [\App\Http\Controllers\DeletionRequestController::class, 'reject'])->middleware('menu:deletion-requests,write');
        });

        // Menus Management
        Route::apiResource('menus', \App\Http\Controllers\MenuController::class);

        // User Permissions Management
        Route::get('users/{user}/permissions', [\App\Http\Controllers\UserPermissionController::class, 'index']);
        Route::put('users/{user}/permissions', [\App\Http\Controllers\UserPermissionController::class, 'update']);
    });
});
