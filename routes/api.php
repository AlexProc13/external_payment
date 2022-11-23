<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\PaymentController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('get_payment_list', [PaymentController::class, 'getPaymentList'])->name('getPaymentList');
Route::post('make_deposit', [PaymentController::class, 'makeDeposit'])->name('makeDeposit');
Route::any('make_deposit_extra', [PaymentController::class, 'makeDepositExtra'])->name('makeDepositExtra');
Route::post('web_hook_deposit', [PaymentController::class, 'webHookDeposit'])->name('webHookDeposit');

