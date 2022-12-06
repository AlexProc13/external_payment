<?php

namespace App\Modules\Finance\Deposits;

use Exception;
use GuzzleHttp\Client;
use App\Helpers\Signature;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NowPayment extends Deposit
{
    protected const FINISH_STATUS = 'finished';
    protected const FAILED_STATUS = 'failed';
    protected const REFUNDED_STATUS = 'refunded';
    protected const EXPIRED_STATUS = 'expired';
    protected const PARTIALLY_STATUS = 'partially_paid';

    protected const TIME_OUT = 25;
    protected const ACTIVE_CURRENCY_URL = "https://api-sandbox.nowpayments.io/v1/merchant/coins";
    protected const PAYMENT_URL = "https://api-sandbox.nowpayments.io/v1/payment";
    protected const STATUSES = [self::FINISH_STATUS, self::FAILED_STATUS, self::REFUNDED_STATUS, self::EXPIRED_STATUS, self::PARTIALLY_STATUS];

    protected $type = 'return';

    public function getExtraData()
    {
        $params = $this->request['params'];
        $client = app()->make(Client::class);
        $response = $client->request('GET', self::ACTIVE_CURRENCY_URL, [
            'headers' => ['x-api-key' => $params['api']],
            'timeout' => self::TIME_OUT,
        ]);
        $responseBody = json_decode($response->getBody()->getContents(), true);
        return ['currencies' => $responseBody['selectedCurrencies'] ?? $responseBody['currencies']];
    }

    public function make()
    {
        $user = $this->request['user'];
        $params = $this->request['params'];
        $request = $this->request['request'];
        $validator = Validator::make($request, [
            'return_url' => 'required|url',
            'amount' => ['required', 'numeric', 'min:0', 'not_in:0'],
            'currency' => 'required',
        ]);

        if ($validator->fails()) {
            throw new Exception('wrong input data');
        }

        $client = app()->make(Client::class);
        $callbackUrl = $this->request['callback'];
        $response = $client->request('POST', self::PAYMENT_URL, [
            'headers' => ['x-api-key' => $params['api']],
            'json' => [
                'price_amount' => $request['amount'],
                'price_currency' => $user['currency'],
                'pay_currency' => $request['currency'],
                'ipn_callback_url' => $callbackUrl,
                'order_id' => $this->request['payment']['invoice_id'],
                'case' => 'failed',
            ],
            'timeout' => self::TIME_OUT,
        ]);

        if ($response->getStatusCode() != 201) {
            Log::error('NowPayment deposit failed.', $response->getBody()->getContents() ?? []);

            throw new Exception('Something went wrong.');
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $dataFormat = [
            'address' => $data['pay_address'],
            'amount' => $data['pay_amount'],
            'currency' => $data['pay_currency'],
        ];
        return $this->makeResponse('stay', $data['payment_id'], $dataFormat);
    }

    public function webHook()
    {
        $request = $this->request['request'];
        $validator = Validator::make($request, [
            'payment_id' => 'required',
            'order_id' => 'required|string',
            'payment_status' => 'required|string',
            'price_amount' => 'required|numeric|min:0|not_in:0',
            'price_currency' => 'required|string',
            'pay_amount' => 'required|numeric|min:0|not_in:0',
            'pay_currency' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new Exception('required_parameter_is_not_included');
        }

        if (!in_array($request['payment_status'], self::STATUSES)) {
            throw new Exception('wrong_status');
        }

        if (!$this->checkSignature($request)) {
            throw new Exception('wrong_signature');
        }

        if (!in_array($request['payment_status'], [self::FINISH_STATUS])) {
            return $this->webHookResponse('fail', self::SYSTEM_STATUSES['fail'], $request['price_amount'], $request['payment_id'], $request['order_id']);
        }

        return $this->webHookResponse('successfully', self::SYSTEM_STATUSES['success'], $request['price_amount'], $request['payment_id'], $request['order_id']);
    }

    protected function checkSignature(array $data): bool
    {
        Log::emergency(json_encode($data));
        $params = $this->request['params'];
        $headers = $this->request['headers'];
        $nowPaymentsSignature = isset($headers['x-nowpayments-sig']) ? $headers['x-nowpayments-sig'][0] : null;

        ksort($data);
        $signature = Signature::makeBySha512(json_encode($data), $params['ipn']);

        if ($nowPaymentsSignature != $signature) {
            Log::error('NowPayment deposit failed.', [
                'signature' => $signature,
                'x-nowpayments-sig' => $nowPaymentsSignature,
            ]);

            return false;
        }
        return true;
    }
}
