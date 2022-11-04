<?php

namespace App\Http\Controllers\API;

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

    public function depositMake(Request $request)
    {
        //validate
        //make
        return [];
    }

    public function depositWebHook(Request $request)
    {
        //validate
        //make
        return [];
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
