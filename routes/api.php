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

//deposits
Route::post('make_deposit_extra', [PaymentController::class, 'makeDepositExtra'])->name('makeDepositExtra');
Route::post('make_deposit', [PaymentController::class, 'makeDeposit'])->name('makeDeposit');
Route::post('web_hook_deposit', [PaymentController::class, 'webHookDeposit'])->name('webHookDeposit');

//withdrawals
Route::post('make_withdrawal_extra', [PaymentController::class, 'makeWithdrawalExtra'])->name('makeWithdrawalExtra');
Route::post('make_withdrawal', [PaymentController::class, 'makeWithdrawal'])->name('makeWithdrawal');
Route::post('web_hook_withdrawal', [PaymentController::class, 'webHookWithdrawal'])->name('webHookWithdrawal');
