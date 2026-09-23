<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Rota pública para visualização do pedido de cotação (Link do Email)
Route::get('/quotation/{token}', [\App\Http\Controllers\PublicQuotationController::class, 'viewRequest']);

// Activação de conta: o utilizador define a sua senha (Link do Email)
Route::get('/email/verify/{token}', [\App\Http\Controllers\EmailVerificationController::class, 'showActivationForm'])
    ->name('email.verify');
Route::post('/email/activate', [\App\Http\Controllers\EmailVerificationController::class, 'activateFromForm'])
    ->name('email.activate');

// Rota pública para registo de fornecedor (Link do Email)
Route::get('/supplier/register/{token}', [\App\Http\Controllers\PublicRegistrationController::class, 'showForm']);
Route::get('/supplier/register/{token}/success', [\App\Http\Controllers\PublicRegistrationController::class, 'success']);
