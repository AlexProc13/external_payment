<?php

namespace App\Http\Controllers\API;

use App\Modules\Finance\Deposits\Deposit;
use Storage;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class PaymentController extends Controller
{
    public function getPaymentList(Request $request)
    {
        $providers = $this->getProviders($request->type);
        return [
            'providers' => $providers,
        ];
    }

    public function makeDeposit(Request $request)
    {
        $payment = app()->make(Deposit::class);
        $data = $payment->make();
        return apiFormatResponse(true, $data);
        return [
            'status' => true,
            'type' => 'return',
            'data' => [
                'link' => 'http://localhost:8080/profile/payments'
            ]
        ];
    }

    public function makeDepositExtra(Request $request)
    {
        $payment = app()->make(Deposit::class);
        $data = $payment->getExtraData();
        return apiFormatResponse(true, $data);
    }

    public function webHookDeposit(Request $request)
    {
        //validate
        //make request and check
        return [
            'status' => true,
            'type' => 1,// 1 -success 2 fail, 3 pending
            'invoice_id' => 13,
            'txid' => 'hash',
            'user_id' => 11,
            'amount' => 22222,
        ];
    }

    public function withdrawalMake(Request $request)
    {
        //validate
        //make
        return [];
    }

    public function withdrawalSendMoney(Request $request)
    {
        //validate
        //make
        return [];
    }

    public function withdrawalWebHook(Request $request)
    {
        //validate
        //make
        return [];
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
