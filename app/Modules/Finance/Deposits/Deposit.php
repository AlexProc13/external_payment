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

    public function webHookResponse($text, $status, $amount, $txid, $invoiceId)
    {
        $params = $this->request['params'];
        return [
            'payment_system_id' => $params['id'],
            'return' => $text,
            'status' => $status,
            'amount' => $amount,
            'txid' => $txid,
            'invoice_id' => $invoiceId,
        ];
    }
}
