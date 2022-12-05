<?php

namespace App\Modules\Finance\Withdrawal;

use Exception;
use Throwable;
use App\Models\User;
use GuzzleHttp\Client;
use App\Helpers\Signature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NowPayment extends Withdrawal
{
    protected const FINISH_STATUS = 'finished';
    protected const FAILED_STATUS = 'failed';
    protected const REFUNDED_STATUS = 'refunded';
    protected const EXPIRED_STATUS = 'expired';

    protected const TIME_OUT = 25;
    protected const PAYMENT_AUTH = "https://api-sandbox.nowpayments.io/v1/payout";
    protected const PAYMENT_URL = "https://api-sandbox.nowpayments.io/v1/payment";
    protected const ACTIVE_CURRENCY_URL = "https://api-sandbox.nowpayments.io/v1/merchant/coins";


    protected const STATUSES = [self::FINISH_STATUS, self::FAILED_STATUS, self::REFUNDED_STATUS, self::EXPIRED_STATUS];
    protected const CRYPTO_CURRENCIES = [
        'BTC', 'USDT.ERC20', 'USDT.BEP20', 'USDT.TRC20', 'CAKE', 'ETH', 'BNBMAINNET',
        'SOL', 'ONE', 'MATIC', 'LTC', 'BCH', 'ZEC', 'TRX', 'DGB', 'RVN', 'DOGE', 'SHIB',
        'OKB', 'XLM', 'DASH', 'LINK', 'DOT', 'ZIL', 'XRP', 'LUNA', 'THETA', 'USDT',
    ];

    public function getExtraData()
    {
        $params = $this->request['params'];
        $user = $this->request['user'];
        $client = app()->make(Client::class);
        $response = $client->request('GET', self::ACTIVE_CURRENCY_URL, [
            'headers' => ['x-api-key' => $params['api']],
            'timeout' => self::TIME_OUT,
        ]);
        $responseBody = json_decode($response->getBody()->getContents(), true);
        return [
            'currencies' => $responseBody['selectedCurrencies'] ?? $responseBody['currencies'],
            'is_crypto' => $this->isCryptoCurrency($user['currency']),
        ];
    }

    public function make()
    {
        $user = $this->request['user'];
        $params = $this->request['params'];
        $request = $this->request['request'];
        $validator = Validator::make($request, [
            'address' => 'required',
            'amount' => ['required', 'numeric', 'min:0', 'not_in:0'],
            'return_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            throw new Exception('wrong input data');
        }

        // if user has fiat currency - currency from request is required
        if (!$this->isCryptoCurrency($user['currency']) && !$request['currency']) {
            throw new Exception('wrong input data');
        }

        //request action
        try {
            $currency = $request['currency'] ?? $user['currency'];
            $data = [
                'address' => $request['address'],
                'currency' => strtolower($currency),
            ];

            if ($this->isCryptoCurrency($user['currency'])) {
                $data['amount'] = $request['amount'];
            } else {
                $data['amount'] = 1;
                $data['fiat_currency'] = $user['currency'];
                $data['fiat_amount'] = $request['amount'];
            }


            $callbackUrl = $this->request['callback'];
            $token = $this->getToken();
            $guzzle = app()->make(Client::class);
            $response = $guzzle->request('POST', self::PAYMENT_URL, [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'x-api-key' => $params['api'],
                ],
                'json' => [
                    'ipn_callback_url' => $callbackUrl,
                    'withdrawals' => [$data],
                ],
                'timeout' => self::TIME_OUT,
            ]);

        } catch (Throwable $exception) {
            Log::info('NowPayment', ['class' => get_class($this), 'error_coin' => dataException($exception)]);
            if (isTimeOutException($exception, self::TIME_OUT)) {
                //can be success
                return $this->makeResponse(self::MAKE_STATUSES['unknown'], null, []);
            }
        }

        //second action
        try {
            $data = json_decode($response->getBody()->getContents(), true);
            $uuid = $data['id'];

        } catch (Throwable $exception) {
            return $this->makeResponse(self::MAKE_STATUSES['fail'], null, $data ?? []);
        }

        return $this->makeResponse(self::MAKE_STATUSES['done'], $uuid, $data);
    }

    public function webHook()
    {
        Log::info('NowPayment withdrawal proceed', $this->request->all());
        $request = $this->request['request'];
        $validator = Validator::make($request, [
            'id' => 'required',
            'batch_withdrawal_id' => 'required',
            'status' => 'required',
            'amount' => 'required|numeric|min:0|not_in:0',
            'currency' => 'required',
            'ipn_callback_url' => 'required',
        ]);

        if ($validator->fails()) {
            throw new Exception('required_parameter_is_not_included');
        }

        if (!in_array(strtolower($request['status']), self::STATUSES)) {
            throw new Exception('wrong_status');
        }

        if (!$this->checkSignature($request)) {
            throw new Exception('wrong_signature');
        }

        if (strtolower($this->request['status']) != self::FINISH_STATUS) {
            throw new Exception('wrong_status');
        }

        return $this->webHookResponse('successfully', self::SYSTEM_STATUSES['success'], $request['amount'], $request['batch_withdrawal_id'], $request['id']);
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

    protected function getToken(): string
    {
        $params = $this->request['params'];
        $guzzle = app()->make(Client::class);
        $response = $guzzle->request('POST', self::PAYMENT_AUTH, [
            'json' => [
                'email' => $params['email'],
                'password' => $params['password'],
            ],
            'timeout' => $params['timeout'],
        ]);

        if ($response->getStatusCode() != 200) {
            Log::error('NowPayment withdraw failed.', $response->getBody()->getContents() ?? []);

            throw new Exception(get_class($this) . 'wrong token');
        }

        $responseBody = json_decode($response->getBody()->getContents(), true);

        return $responseBody['token'];
    }

    protected function isCryptoCurrency($currency)
    {
        return in_array($currency, self::CRYPTO_CURRENCIES);
    }
}
