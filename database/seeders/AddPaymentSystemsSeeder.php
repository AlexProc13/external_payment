<?php

namespace Database\Seeders;

use Storage;
use App\Models\PaymentClass;
use App\Models\PaymentSystem;
use App\Models\CompanyPayment;
use Illuminate\Database\Seeder;

class AddPaymentSystemsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //step 0
        $this->setProviders($this->specialProviders()['deposits'], config('finance.payments.types')['deposit']);
        //step 1 system providers
        $this->setUpDefault();

        //step 2
        $providers = $this->getProviders();

        $this->setProviders($providers['deposits'], config('finance.payments.types')['deposit']);

        $this->setProviders($providers['withdrawals'], config('finance.payments.types')['withdrawal']);
    }

    protected function setUpDefault()
    {
        foreach (config('finance.payments.providers') as $item) {
            $existPayment = CompanyPayment::where('id', $item['id'])->first();

            $paymentGeneral = PaymentSystem::firstOrCreate(
                ['hash' => crc32('System')],
                ['name' => 'System']
            );

            //create deps
            $class = PaymentClass::firstOrCreate(
                ['hash' => crc32($item['class'])],
                ['class' => $item['class'], 'type' => config('finance.type_class')['class']]
            );

            $hash = getPaymentHash($item['code'], 0, $item['code']);

            if ($existPayment) {
                $payment = $existPayment;
            } else {
                $payment = new CompanyPayment();
                $payment->id = $item['id'];
                $payment->hash = $hash;
            }

            $payment->type = 0;
            $payment->name = isset($item['name']) ? $item['name'] : $item['code'];
            $payment->code = $item['code'];
            $payment->class_id = $class->id;
            $payment->payment_system_id = $paymentGeneral->id;
            $payment->hook_type = config('finance.typeHooks')['empty'];
            $payment->save();
        }
    }

    protected function setProviders($items, $type)
    {
        //step 2
        foreach ($items as $item) {
            //create general payment
            $paymentGeneral = PaymentSystem::firstOrCreate(
                ['hash' => crc32($item['payment'])],
                ['name' => $item['payment']]
            );

            //create deps
            $class = PaymentClass::firstOrCreate(
                ['hash' => crc32($item['class'])],
                ['class' => $item['class'], 'type' => config('finance.type_class')['class']]
            );

            $gateWay = null;
            if (isset($item['gateway_class'])) {
                $gateWay = PaymentClass::firstOrCreate(
                    ['hash' => crc32($item['gateway_class'])],
                    ['class' => $item['gateway_class'], 'type' => config('finance.type_class')['gateway']]
                );
            }
            //create deps

            $hash = getPaymentHash($item['code'], $type, $item['name']);
            if (isset($item['id'])) {
                $hash = getPaymentHash($item['code'], $type, $item['name'], $item['id']);
            }
            $existPayment = CompanyPayment::where('hash', $hash)->first();

            if ($existPayment) {
                $payment = $existPayment;
            } else {
                $payment = new CompanyPayment();

                if (isset($item['id'])) {
                    $payment->id = $item['id'];
                }

                $payment->hash = $hash;
                $payment->name = $item['name'];
                $payment->type = $type;
                $payment->code = $item['code'];

                $payment->data = $item['data'] ?? null;
                $payment->path = $item['path'] ?? null;

                $payment->min = isset($item['data']['minLimit']) ? $item['data']['minLimit'] : 0;
                $payment->max = isset($item['data']['maxLimit']) ? $item['data']['maxLimit'] : 0;

                //create payment
                $payment->class_id = $class->id;
                $payment->payment_system_id = $paymentGeneral->id;
                $payment->hook_type = $item['hook_type'];

                $payment->group = isset($item['group']) ? crc32($item['group']) : 0;
                $payment->gateway_id = isset($gateWay) ? $gateWay->id : null;
                $payment->front_group = $item['front_group'] ?? null;
                $payment->front_dir = $item['front_dir'] ?? null;

                $payment->save();
                //create payment
            }
        }
    }

    protected function specialProviders()
    {
        return [
            'deposits' => [
                [
                    'id' => 2,
                    'name' => 'Bitcoin',
                    'code' => 'BlockChain',
                    'class' => \App\Modules\Finance\Deposits\BlockChain::class,
                    'hook_type' => config('finance.typeHooks')['usual_deposit'],
                    'path' => '/images/payments/bitcoin.png',
                    'payment' => 'BlockChain',
                    'data' => [
                        'timeout' => 5,
                        'url' => 'https://api.blockchain.info/v2',
                        'salt' => 'test',
                        'signer' => 'test',
                        'hide' => true,
                        'methods' => [
                            'receive' => 'receive',
                            'checkgap' => 'receive/checkgap',
                        ],
                        'minLimit' => 1,
                        'maxLimit' => 10000,
                        'extra' => true,
                        'ttlAddress' => 360,//in min
                        'allowCurrencies' => ['mBTC'],
                        'dependsOn' => ['d_key_block_chain' => 'api_key', 'd_xpub_block_chain' => 'xpub'],
                    ]
                ]
            ],
        ];
    }

    protected function getProviders()
    {
        //todo BEFORE ADD CLASS THERE CHECK config/finance (providers blocks). Don't use this id's for new payments.
        return [
            'deposits' => $this->loadProviders('payments/deposits'),
            'withdrawals' => $this->loadProviders('payments/withdrawals')
        ];
    }

    protected function loadProviders($path)
    {
        $deposits = [];
        $disk = Storage::disk('system');
        $files = $disk->files($path);
        foreach ($files as $file) {
            $items = $disk->get($file);
            $items = json_decode($items, true);
            $deposits = array_merge($deposits, $items);
        }
        return $deposits;
    }
}
