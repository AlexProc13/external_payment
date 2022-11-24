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
    protected $user;
    protected $request;
    protected $params;
    protected $currency;
    protected Invoice|null $invoice = null;

    public function __construct($request, $params)
    {
        $this->params = $params;
        $this->request = $request;
        $this->user = auth()->user();
        if ($this->user) {
            $this->currency = Currency::where('code', $this->user->currency)->first();
        }
    }

    abstract public function getPayment();

    abstract public function make();

    abstract public function webHook();

    public function getInvoice(): Invoice|null
    {
        return $this->invoice;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function validate()
    {
        $params = $this->params;
        $request = $this->request;

        $this->canWithdrawal();

        $this->canWithdrawalWithBonus();

        $this->checkLimit();

        //run dynamic limits
        $limitStatus = $this->canByDayLimit();
        if (!$limitStatus) {
            throw new ApiException('payment.max_withdrawal_day');
        }

        $this->checkOpenWithdrawal();
    }

    public function checkLimit()
    {
        $user = $this->user;
        $params = $this->params;
        $request = $this->request;

        if (!$request->has('amount')) {
            return true;
        }

        $minLimit = convertFromCompanyToPlayerAndRound($params['minLimit'], $user);
        $maxLimit = convertFromCompanyToPlayerAndRound($params['maxLimit'], $user);

        if ((float)$request->amount < $minLimit or (float)$request->amount > $maxLimit) {
            throw new ApiException('payment.withdrawal_limit');
        }
    }

    public function isManualMode()
    {
        $user = $this->user;
        $amount = $this->request->amount;

        //MANUAL APPROVE
        $userApprove = CompanySetting::where('company_id', $user->company_id)
            ->where('key', config('auth.user_settings.user_withdrawal_approve'))->first();

        if (!$userApprove) {
            return false;
        }

        $userApproveValue = convertFromCompanyToPlayer((float)$userApprove->value, $user);
        if ((float)$amount >= $userApproveValue) {
            return true;
        }

        return false;
    }

    public function canByDayLimit()
    {
        $user = $this->user;
        $amount = $this->request->amount;
        $financeData = config('finance.payments');

        //DAY LIMIT
        $limitDay = CompanySetting::where('company_id', $user->company_id)
            ->where('key', config('auth.user_settings.user_limit_withdrawal_day'))->first();

        $limitDayMember = MemberDailyLimitService::getMemberLimit($user->id);

        $userGroup = $user->usersGroup;
        if ($userGroup) {
            $limitDayGroup = $userGroup->settings()
                ->where('key', config('auth.user_settings.user_limit_withdrawal_day'))->first();
        }

        $limitDayUser = UserSetting::where('user_id', $user->id)
            ->where('key', config('auth.user_settings.user_limit_withdrawal_day'))->first();

        if ($limitDayMember) {
            $limitDay = (object)['value' => $limitDayMember];
        }

        if (isset($limitDayGroup) && $limitDayGroup) {
            $limitDay = $limitDayGroup;
        }

        if ($limitDayUser) {
            $limitDay = $limitDayUser;
        }

        if ($limitDay && $limitDay->value) {
            //count payment with this request and throw or not throw exception
            $daysPayment = Payment::select(DB::raw('SUM(value) as total'))
                ->where('user_id', $user->id)
                ->where('type', $financeData['types']['withdrawal'])
                ->where('status', $financeData['statuses']['completed'])
                ->whereDate('created_at', Carbon::today())
                ->first();

            $totalAmount = (float)$daysPayment->total * -1 + (float)$amount;//in user currency
            $limitDayValue = convertFromCompanyToPlayer((float)$limitDay->value, $user);//to user currency

            if ($totalAmount >= $limitDayValue) {
                return false;
            }
        }
        return true;
    }

    public function getExtraData()
    {
        return [];
    }

    public function getExtraRequest()
    {
        return [];
    }

    public function getExtra(array $extraData)
    {
        return $extraData;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function makeRequestData(Invoice $invoice): array
    {
        $data = json_decode($invoice->origin, true);

        return [
            'amount' => $data['amount'],
            'address' => $data['address'],
            'bankId' => $data['bankId'] ?? 1,
            'name' => $data['accountName'] ?? null,
            'currency' => $data['currency'] ?? null,
        ];
    }

    protected function checkOpenWithdrawal()
    {
        $user = auth()->user();
        $pendingPayment = Payment::getPendingPayment($user);

        if ($pendingPayment) {
            throw new ApiException('payment.withdrawal_one_pending');
        }
    }

    protected function checkSignature(array $data): bool
    {
        return true;
    }

    protected function canWithdrawal(): void
    {
        $wagerType = DepositWagering::getWagerType($this->user->company_id);

        if ($wagerType == DepositWagering::GLOBAL) {
            //check deposit wagering
            $isWager = DepositWagering::isWager($this->user);
            if (!$isWager) {
                throw new ApiException('payment.withdrawal_wagering');
            }
        }

        if ($wagerType == DepositWagering::CONDITION) {
            $userFinance = UserFinance::where('user_id', $this->user->id)->first();

            if (!$userFinance || $userFinance['deposit_wager'] - $userFinance['withdrawal_wager'] - $this->request['amount'] < 0) {
                throw new ApiException('payment.withdrawal_condition');
            }
        }
    }

    protected function canWithdrawalWithBonus()
    {
        $user = $this->user;
        if (is_null($user->bonus_id)) {
            return true;
        }
        $userBonus = UserBonus::where('id', $user->bonus_id)
            ->whereIn('status', [config('bonuses.bonusStatus')['realActive'], config('bonuses.bonusStatus')['active']])
            ->first();

        if (is_null($userBonus)) {
            return true;
        }

        $bonusData = $userBonus->data;
        if ($bonusData['restrict_withdrawal'] and $bonusData['restrict_withdrawal'] == config('bonuses.restrict.yes')) {
            throw new ApiException('bonuses.bonus_withdrawal_limit');
        }

        throw new ApiException('bonuses.bonus_withdrawal_ask');
    }
}
