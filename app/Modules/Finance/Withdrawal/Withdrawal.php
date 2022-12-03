<?php

namespace App\Modules\Finance\Withdrawal;

use App\Models\Invoice;
use App\Models\UserFinance;
use App\Services\Company\MemberDailyLimitService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Payment;
use App\Models\Currency;
use App\Models\UserBonus;
use App\Models\UserSetting;
use App\Models\CompanySetting;
use App\Exceptions\ApiException;
use App\Modules\Bonuses\DepositWagering;

abstract class Withdrawal
{
    protected $request;
    protected $type = null;
    protected const SYSTEM_STATUSES = [
        'success' => 'success',
        'fail' => 'fail',
        'pending' => 'pending',
    ];

    protected const MAKE_STATUSES = [
        'done' => 'done',
        'unknown' => 'unknown',
        'fail' => 'fail',
    ];

    public function __construct($request)
    {
        $this->request = $request;
    }

    abstract public function make();

    abstract public function webHook();

    public function getExtraData()
    {
        return [];
    }

    public function webHookResponse($text, $status, $amount, $txid = null, $invoiceId = null, $userId = null)
    {
        $params = $this->request['params'];
        $data = [
            'payment_system_id' => $params['id'],
            'return' => $text,
            'status' => $status,
            'amount' => $amount,

            'txid' => $txid,
            'invoice_id' => $invoiceId,
        ];

        if (isset($txid)) {
            $data['txid'] = $txid;
        }

        if (isset($invoiceId)) {
            $data['invoice_id'] = $invoiceId;
        }

        if (isset($userId)) {
            $data['user_id'] = $userId;
        }
        return $data;
    }

    /**
     *
     * @param $action
     * @param $uuid
     * @param $data
     * @return array
     */
    public function makeResponse(string $action, string|null $uuid, array $data)
    {
        $default = [
            'txid' => $uuid,
            'action' => $action
        ];

        return array_merge($default, $data);
    }
}
