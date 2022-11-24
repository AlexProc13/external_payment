<?php

namespace App\Modules\Finance\Withdrawal;

use App\Events\User\ChangeBalance;
use App\Events\WithdrawalSuccess;
use App\Exceptions\ApiException;
use App\Helpers\Signature;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Bonuses\DepositWagering;
use App\Rules\CheckStake;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Exception;

class NowPayment extends Withdrawal
{
    use WithdrawalTrait;

    protected const FINISH_STATUS = 'finished';
    protected const FAILED_STATUS = 'failed';
    protected const REFUNDED_STATUS = 'refunded';
    protected const EXPIRED_STATUS = 'expired';

    protected const STATUSES = [
        self::FINISH_STATUS, self::FAILED_STATUS, self::REFUNDED_STATUS, self::EXPIRED_STATUS
    ];

    protected $payment;

    public function getPayment()
    {
        return $this->payment;
    }

    public function getExtraData()
    {
        try {
            $guzzle = app()->make(Client::class);
            $response = $guzzle->request('GET', $this->params['data']['extraData']['activeCurrenciesUrl'], [
                'headers' => ['x-api-key' => $this->params['api']],
                'timeout' => $this->params['timeout'],
            ]);
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            return [];
        }

        $responseBody = json_decode($response->getBody()->getContents(), true);

        return [
            'currencies' => $responseBody['selectedCurrencies'] ?? $responseBody['currencies'],
            'is_crypto' => auth()->user()->isCryptoCurrency(),
        ];
    }

    public function validate()
    {
        $user = auth()->user();

        $validator = Validator::make($this->request->toArray(), [
            'address' => 'required',
            'amount' => ['required', 'numeric', 'min:0', 'not_in:0', new CheckStake()],
            'return_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            throw new ApiException('common.required_parameter_is_not_included');
        }

        // if user has fiat currency - currency from request is required
        if (!$user->isCryptoCurrency() && !$this->request['currency']) {
            throw new ApiException('common.required_parameter_is_not_included');
        }

        if ((float) $this->request['withdrawal'] > $user['withdrawal']) {
            throw new ApiException('common.required_parameter_is_not_included');
        }

        parent::validate();
    }

    public function make()
    {
        DB::beginTransaction();

        $user = User::where('id', auth()->id())->lockForUpdate()->firstOrFail();
        $amount = -1 * formatValue($this->request['amount'], 'fullAmount');

        if (($user->withdrawal + $amount) < 0) {
            throw new ApiException('payment.not_enough_balance_operation');
        }

        $user->balance += $amount;
        $user->withdrawal += $amount;
        $user->save();

        $invoice = Invoice::create([
            'uuid' => null,
            'user_id' => $user->id,
            'type' => $this->params['id'],
            'category' => config('finance.payments.invoiceType.withdrawal'),
            'status' => config('finance.payments.statuses.pending'),
            'origin' => json_encode($this->request->origin),
            'data' => json_encode([]),
            'response' => json_encode([]),
        ]);

        $transaction = Transaction::create([
            'type' => config('additional.transactions.type.frozen'),
            'user_id' => $user->id,
            'sum' => $amount,
            'comment' => 'withdrawal by ' . $this->params['code'],
            'extra' => json_encode(['balance' => $user->balance]),
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'type' => config('finance.payments.types.withdrawal'),
            'value' => $amount,
            'provider' => $this->params['name'],
            'invoice_id' => $invoice->id,
            'transaction_id' => $transaction->id,
            'status' => config('finance.payments.statuses.pending'),
            'initiator_id' => $this->params['id'],
            'extra' => json_encode(['balance' => $user->balance]),
        ]);

        DB::table('transaction_relations')->insert([
            'type' => config('additional.transactionRelations.payment'),
            'transaction_id' => $transaction->id,
            'related_id' => $payment->id,
        ]);

        DB::commit();

        $data = [
            'address' => $this->request['address'],
            'amount' => $this->request['amount'],
            'currency' => $this->request['currency'] ?? $user['currency'],
        ];

        $this->makeRequestWithdrawal($payment, $user, $data);

        event(new ChangeBalance($user));

        return [
            'redirect_url' => $this->request['return_url'],
        ];
    }

    public function makeRequestWithdrawal($payment, $user, $data): void
    {
        $this->block($payment);

        $invoice = Invoice::where('id', $payment->invoice_id)->firstOrFail();

        $withdrawal = [
            'address' => $this->request['address'],
            'currency' => strtolower($data['currency']),
        ];

        if (auth()->user()->isCryptoCurrency()) {
            $withdrawal['amount'] = $data['amount'];
        } else {
            $withdrawal['amount'] = 1;
            $withdrawal['fiat_currency'] = $user['currency'];
            $withdrawal['fiat_amount'] = $data['amount'];
        }

        try {
            $callbackParams = [$this->params['id'], $this->params['companyId']];
            $callbackUrl = getRouteByUrl(config('app.noCheckBotsHost'), 'api.payments.withdrawalWebHooks', $callbackParams);
            $token = $this->getToken();
            $guzzle = app()->make(Client::class);
            $response = $guzzle->request('POST', $this->params['url'], [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'x-api-key' => $this->params['api'],
                ],
                'json' => [
                    'ipn_callback_url' => $callbackUrl,
                    'withdrawals' => [$withdrawal],
                ],
                'timeout' => $this->params['timeout'],
            ]);

            if ($response->getStatusCode() != 200) {
                Log::error("NowPayment withdrawal failed. invoice #{$invoice['id']}", $response->getBody()->getContents() ?? []);

                throw new ApiException('Something went wrong.');
            }

            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data['id']) {
                Log::error("NowPayment withdrawal failed. invoice #{$invoice['id']}", $data);

                throw new ApiException('Something went wrong.');
            } else {
                DB::beginTransaction();

                $this->unBlock($payment);

                $invoice->data = json_encode(['request' => $this->request->toArray(), 'response' => $data]);
                $invoice->uuid = $data['id'];
                $invoice->save();

                DB::commit();
            }
        } catch (\Throwable $e) {
            Log::error("NowPayment withdrawal failed. invoice #{$invoice['id']}", dataException($e));

            DB::beginTransaction();

            $this->unBlock($payment);
            $this->generalReject($user, $payment->id);

            DB::commit();
        }
    }

    public function webHook()
    {
        Log::info('NowPayment withdrawal proceed', $this->request->all());

        try {
            $validator = Validator::make($this->request->all(), [
                'id' => 'required',
                'batch_withdrawal_id' => 'required',
                'status' => 'required',
                'amount' => 'required|numeric|min:0|not_in:0',
                'currency' => 'required',
                'ipn_callback_url' => 'required',
            ]);

            if (!in_array(strtolower($this->request['status']), self::STATUSES)) {
                return 'wrong_status';
            }

            if ($validator->fails()) {
                throw new Exception('required_parameter_is_not_included');
            }

            if (!$this->checkSignature($this->request->toArray())) {
                throw new Exception('wrong_signature');
            }

            $invoice = Invoice::where('uuid', $this->request['batch_withdrawal_id'])->where('type', $this->params['id'])
                ->where('category', config('finance.payments.invoiceType.withdrawal'))
                ->where('status', config('finance.payments.statuses.pending'))
                ->first();

            if (!$invoice) {
                throw new Exception('invoice_not_found');
            }

            DB::beginTransaction();

            $user = User::where('id', $invoice['user_id'])->lockForUpdate()->first();
            $payment = Payment::where('invoice_id', $invoice->id)->where('status', config('finance.payments.statuses.pending'))->first();

            if (!$payment) {
                throw new Exception('payment_not_found');
            }

            $transactionFrozen = Transaction::where('id', $payment->transaction_id)->firstOrFail();
            $frozenAmount = -1 * $transactionFrozen->sum;

            $unFrozen = Transaction::create([
                'type' => config('additional.transactions.type.unFrozen'),
                'user_id' => $user->id,
                'sum' => $frozenAmount,
                'comment' => 'withdrawal by ' . $this->params['code'],
                'extra' => json_encode(['balance' => $user->balance + $frozenAmount]),
            ]);

            DB::table('transaction_relations')->insert([
                'type' => config('additional.transactionRelations.payment'),
                'transaction_id' => $unFrozen->id,
                'related_id' => $payment->id,
            ]);

            if (strtolower($this->request['status']) == self::FINISH_STATUS) {
                $transaction = Transaction::create([
                    'type' => config('additional.transactions.type.withdrawal'),
                    'user_id' => $user->id,
                    'sum' => $transactionFrozen->sum,
                    'comment' => 'withdrawal by ' . $this->params['code'],
                    'extra' => json_encode(['balance' => $user->balance]),
                ]);

                DB::table('transaction_relations')->insert([
                    'type' => config('additional.transactionRelations.payment'),
                    'transaction_id' => $transaction->id,
                    'related_id' => $payment->id,
                ]);

                $invoice->uuid = $this->request['id'];
                $invoice->status = config('finance.payments.statuses.completed');
                $invoice->response = json_encode($this->request->toArray());
                $invoice->save();

                $payment->transaction_id = $transaction->id;
                $payment->status = config('finance.payments.statuses.completed');
                $payment->save(); // todo check

                DB::commit();

                event(new ChangeBalance($user));
                event(new WithdrawalSuccess($user, $payment));

                return 'successfully';
            }

            $user->balance += $frozenAmount;
            $user->withdrawal += $frozenAmount;
            $user->save();

            $invoice->uuid = $this->request['id'];
            $invoice->status = config('finance.payments.statuses.error');
            $invoice->response = json_encode($this->request->toArray());
            $invoice->save();

            $payment->status = config('finance.payments.statuses.error');
            $payment->save(); // todo check

            DB::commit();

            event(new ChangeBalance($user));

            return 'wrong_request';
        } catch (Throwable $e) {
            DB::rollback();

            $invoiceId = $invoice['id'] ?? $this->request['id'] ?? -1;
            Log::error("NowPayment withdrawal failed. invoice #{$invoiceId}", dataException($e));

            return 'some_is_wrong';
        }
    }

    protected function checkSignature(array $data): bool
    {
        ksort($data);
        $signature = Signature::makeBySha512(json_encode($data), $this->params['ipn']);

        if ($this->request->header('x-nowpayments-sig') != $signature) {
            Log::error('NowPayment withdrawal failed.', [
                'signature' => $signature,
                'x-nowpayments-sig' => $this->request->header('x-nowpayments-sig'),
            ]);

            return false;
        }

        return true;
    }

    protected function getToken(): string
    {
        $guzzle = app()->make(Client::class);
        $response = $guzzle->request('POST', $this->params['data']['extraData']['authUrl'], [
            'json' => [
                'email' => $this->params['email'],
                'password' => $this->params['password'],
            ],
            'timeout' => $this->params['timeout'],
        ]);

        if ($response->getStatusCode() != 200) {
            Log::error('NowPayment withdraw failed.', $response->getBody()->getContents() ?? []);

            throw new ApiException('Something went wrong.');
        }

        $responseBody = json_decode($response->getBody()->getContents(), true);

        return $responseBody['token'];
    }
}
