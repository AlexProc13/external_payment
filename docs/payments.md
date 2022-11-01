## How add new payment?
1. Go to `database/seed/AddPaymentSystemsSeeder.php`;
2. Add payment array - see examples in file.
```
[
    'name' => 'Tether USD (ERC20)',
    'code' => 'CoinPaymentsByAddress',
    'class' => App\Modules\Finance\Deposits\CoinPayments::class,
    'hook_type' => config('finance.typeHooks')['usual_deposit'],
    'path' => '/images/payments/usdt.png',
    'payment' => 'CoinPayments',
    'data' => [
        'timeout' => 30,
        'url' => 'https://www.coinpayments.net/api.php',
        'minLimit' => 30,
        'maxLimit' => 1000000,
        'allowCurrencies' => ['USDT.ERC20', 'ETH', 'TRX', 'BTC', 'LTC', 'DASH'],
        'extra' => true,
        'hideRange' => true,
        'extraData' => [
            'item_desc' => 'desc_usdt_erc',
            'icons' => []
        ],
        'dependsOn' => ['d_coin_pub' => 'public', 'd_coin_priv' => 'private'],
    ],
],
```
4. When You develop new payments, sometimes you need create a webhook.Some domain name can be protected by proxy servers and request for payment cannot be reached. In this case need use ```getRouteByUrl``` for creating webhooks. Example:
```
$callBackUrl = getRouteByUrl(config('app.noCheckBotsHost'), 'api.payments.depositWebHooks', [$this->params['id'], $this->params['companyId']]);
```

## How Set up BlockChain?
Need create instance of payment and run method getFullData. Example:
```
        $companyUid = 3; //set proper value
        $providerId = 3; //set proper value

        $deposit = app()->make(Deposit::class, ['type' => $providerId, 'company_uuid' => $companyUid]);

        if ($deposit) {
            $deposit->getFullData();
        }
```
