<?php

namespace App\Modules\Finance\Deposits;

abstract class Deposit
{
    protected $request;
    protected $type = null;
    protected const SYSTEM_STATUSES = [
        'success' => 'success',
        'fail' => 'fail',
        'pending' => 'pending',
    ];

    public function __construct($request)
    {
        $this->request = $request;
    }

    public function getExtraData()
    {
        return [];
    }

    abstract public function make();

    abstract public function webHook();

    public function getType()
    {
        return $this->type;
    }

    public function webHookResponse($text, $status, $amount, $txid = null, $invoiceId = null, $userId = null)
    {
        $params = $this->request['params'];
        $data =  [
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

    public function makeResponse($action, $uuid, $data)
    {
        $default = [
            'txid' => $uuid,
            'action' => $action
        ];

        return array_merge($default, $data);
    }
}
