<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Modules\Finance\Deposits\Deposit;
use App\Modules\Finance\Withdrawal\Withdrawal;

class PaymentController extends Controller
{
    public function getPaymentList(Request $request)
    {
        $providers = $this->getProviders($request->type);
        return ['providers' => $providers];
    }

    public function makeDepositExtra(Request $request)
    {
        $payment = app()->make(Deposit::class);
        $data = $payment->getExtraData();
        return apiFormatResponse(true, $data);
    }

    public function makeDeposit(Request $request)
    {
        $payment = app()->make(Deposit::class);
        $data = $payment->make();
        return apiFormatResponse(true, $data, ['type' => $payment->getType()]);
    }

    public function webHookDeposit(Request $request)
    {
        $payment = app()->make(Deposit::class);
        $data = $payment->webhook();
        return apiFormatResponse(true, $data);
    }

    public function makeWithdrawalExtra(Request $request)//Withdrawal
    {
        $payment = app()->make(Withdrawal::class);
        $data = $payment->getExtraData();
        return apiFormatResponse(true, $data);
    }

    public function makeWithdrawal(Request $request)
    {
        $payment = app()->make(Withdrawal::class);
        $data = $payment->make();
        return apiFormatResponse(true, $data, ['action' => $payment->getAction($data)]);
    }

    public function webHookWithdrawal(Request $request)
    {
        $payment = app()->make(Withdrawal::class);
        $data = $payment->webhook();
        return apiFormatResponse(true, $data);
    }

    protected function getProviders($path)
    {
        $providers = [];
        $disk = Storage::disk('system');
        $files = $disk->allFiles('payments/' . $path);
        foreach ($files as $file) {
            $items = $disk->get($file);
            $items = json_decode($items, true);
            $providers = array_merge($providers, $items);
        }
        return $providers;
    }
}
