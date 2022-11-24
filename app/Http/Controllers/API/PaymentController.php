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
        return apiFormatResponse(true, $data, ['type' => $payment->getType()]);
    }

    public function makeDepositExtra(Request $request)
    {
        $payment = app()->make(Deposit::class);
        $data = $payment->getExtraData();
        return apiFormatResponse(true, $data);
    }

    public function webHookDeposit(Request $request)
    {
        $payment = app()->make(Deposit::class);
        $data = $payment->webhook();
        return apiFormatResponse(true, $data);
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
