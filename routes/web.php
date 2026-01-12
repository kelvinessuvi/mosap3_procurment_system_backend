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
