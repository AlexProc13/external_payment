<?php

namespace Tests\Feature\Payments\NowPayments;

use Tests\TestCase;
use App\Models\User;
use GuzzleHttp\Client;
use App\Helpers\Signature;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\WithFaker;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

class DepositTest extends TestCase
{
    use WithFaker;

    protected $class = \App\Modules\Finance\Deposits\NowPayment::class;

    protected const CONFIRM_STATUS = 'confirmed';
    protected const CANCEL_STATUS = 'cancelled';
    protected const COINS_PAID_DEPOSIT = 'deposit';

    protected const STATUSES = [
        self::CONFIRM_STATUS, self::CANCEL_STATUS,
    ];

    protected string $currency = 'USD';
    protected array $headers = [];
    protected User $user;

    public function setUp(): void
    {
        parent::setUp();
        $this->input = [
            'params' => [
                'id' => $this->faker->numberBetween(1, 200),
                'ipn' => $this->faker->uuid,
                'api' => $this->faker->uuid,
                'data' => [
                    'originClass' => $this->class,
                ]
            ]
        ];
    }

    public function testGetExtraData()
    {
        $expectedResult = [
            'currencies' => [
                'btc', 'usdt',
            ],
        ];

        $mock = new MockHandler([new GuzzleResponse(200, [], json_encode($expectedResult))]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $this->app->instance(Client::class, $client);
        $response = $this->json(Request::METHOD_POST, route('makeDepositExtra'), $this->input, $this->headers);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'currencies'
                ],
            ]);
    }

    public function testGetExtraDataError()
    {
        $response = $this->json(Request::METHOD_POST, route('makeDepositExtra'), [], $this->headers);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('status', false)
            ->assertJsonStructure([
                'status',
                'data',
            ]);
    }

    public function testMake()
    {
        $needPayIn = 'TRX';
        $userCurrency = 'USD';

        $invoiceId = $this->faker->numberBetween(1, 200);
        $input = $this->input;
        $input['user'] = [
            'id' => $this->faker->numberBetween(1, 200),
            'currency' => $userCurrency,
        ];
        $input['request'] = [
            'amount' => $this->faker->numberBetween(100, 200),
            'currency' => $needPayIn,
            'return_url' => $this->faker->url,
        ];
        $input['callback'] = $this->faker->url;
        $input['payment'] = [
            'invoice_id' => $invoiceId,
        ];

        $expectedResult = [
            'payment_id' => $this->faker->numberBetween(100, 200),
            'invoice_id' => $invoiceId,
            'status' => 'waiting',
            'pay_address' => $this->faker->uuid,
            'pay_amount' => $this->faker->numberBetween(100, 200),
            'pay_currency' => $needPayIn,
        ];
        $mock = new MockHandler([new GuzzleResponse(201, [], json_encode($expectedResult))]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $this->app->instance(Client::class, $client);

        $response = $this->json(Request::METHOD_POST, route('makeDeposit'), $input, $this->headers);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'currency',
                    'amount',
                    'address',
                    'action',
                    'txid',
                ]
            ]);
    }

    public function testMakeError()
    {
        $needPayIn = 'TRX';
        $userCurrency = 'USD';

        $invoiceId = $this->faker->numberBetween(1, 200);
        $input = $this->input;
        $input['user'] = [
            'id' => $this->faker->numberBetween(1, 200),
            'currency' => $userCurrency,
        ];
        $input['request'] = [
            //wrong input
        ];
        $input['callback'] = $this->faker->url;
        $input['payment'] = [
            'invoice_id' => $invoiceId,
        ];

        $response = $this->json(Request::METHOD_POST, route('makeDeposit'), $input, $this->headers);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('status', false)
            ->assertJsonStructure([
                'status',
                'data',
            ]);
    }

    public function testWebHook()
    {
        $needPayIn = 'TRX';
        $userCurrency = 'USD';
        $paymentId = $this->faker->numberBetween(1, 200);
        $invoiceId = $this->faker->numberBetween(1, 200);

        $input = $this->input;
        $input['user'] = [
            'id' => $this->faker->numberBetween(1, 200),
            'currency' => $userCurrency,
        ];
        $input['request'] = [
            'payment_id' => $paymentId,
            'order_id' => $invoiceId,
            'payment_status' => 'finished',
            'price_amount' => $this->faker->numberBetween(100, 200),
            'price_currency' => $userCurrency,
            'pay_amount' => $this->faker->numberBetween(1, 5),
            'pay_currency' => $needPayIn,
        ];
        $input['callback'] = $this->faker->url;
        $input['payment'] = [
            'invoice_id' => $invoiceId,
        ];

        $request = $input['request'];
        ksort($request);
        $signature = Signature::makeBySha512(json_encode($request), $input['params']['ipn']);
        $input['headers'] = [
            'x-nowpayments-sig' => [$signature],
        ];

        $response = $this->json(Request::METHOD_POST, route('webHookDeposit'), $input);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.payment_system_id', $this->input['params']['id'])
            ->assertJsonPath('data.txid', $paymentId)
            ->assertJsonPath('data.amount', $input['request']['price_amount'])
            ->assertJsonPath('data.return', 'successfully')
            ->assertJsonPath('data.invoice_id', $invoiceId)
            ->assertJsonStructure([
                'status',
                'data',
            ]);
    }

    public function testWebHookFail()
    {
        $needPayIn = 'TRX';
        $userCurrency = 'USD';
        $paymentId = $this->faker->numberBetween(1, 200);
        $invoiceId = $this->faker->numberBetween(1, 200);

        $input = $this->input;
        $input['user'] = [
            'id' => $this->faker->numberBetween(1, 200),
            'currency' => $userCurrency,
        ];
        $input['request'] = [
            'payment_id' => $paymentId,
            'order_id' => $invoiceId,
            'payment_status' => 'refunded',
            'price_amount' => $this->faker->numberBetween(100, 200),
            'price_currency' => $userCurrency,
            'pay_amount' => $this->faker->numberBetween(1, 5),
            'pay_currency' => $needPayIn,
        ];
        $input['callback'] = $this->faker->url;
        $input['payment'] = [
            'invoice_id' => $invoiceId,
        ];

        $request = $input['request'];
        ksort($request);
        $signature = Signature::makeBySha512(json_encode($request), $input['params']['ipn']);
        $input['headers'] = [
            'x-nowpayments-sig' => [$signature],
        ];

        $response = $this->json(Request::METHOD_POST, route('webHookDeposit'), $input);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.payment_system_id', $this->input['params']['id'])
            ->assertJsonPath('data.txid', $paymentId)
            ->assertJsonPath('data.amount', $input['request']['price_amount'])
            ->assertJsonPath('data.return', 'fail')
            ->assertJsonPath('data.return', 'fail')
            ->assertJsonPath('data.invoice_id', $invoiceId)
            ->assertJsonStructure([
                'status',
                'data',
            ]);
    }

    public function testWebHookError()
    {
        $needPayIn = 'TRX';
        $userCurrency = 'USD';
        $paymentId = $this->faker->numberBetween(1, 200);
        $invoiceId = $this->faker->numberBetween(1, 200);

        $input = $this->input;
        $input['user'] = [
            'id' => $this->faker->numberBetween(1, 200),
            'currency' => $userCurrency,
        ];
        $input['request'] = [
            'payment_id' => $paymentId,
            'order_id' => $invoiceId,
            'payment_status' => 'waiting',
            'price_amount' => $this->faker->numberBetween(100, 200),
            'price_currency' => $userCurrency,
            'pay_amount' => $this->faker->numberBetween(1, 5),
            'pay_currency' => $needPayIn,
        ];
        $input['callback'] = $this->faker->url;
        $input['payment'] = [
            'invoice_id' => $invoiceId,
        ];

        $request = $input['request'];
        ksort($request);
        $signature = Signature::makeBySha512(json_encode($request), $input['params']['ipn']);
        $input['headers'] = [
            'x-nowpayments-sig' => [$signature],
        ];

        $response = $this->json(Request::METHOD_POST, route('webHookDeposit'), $input);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('status', false)
            ->assertJsonStructure([
                'status',
                'data',
            ]);
    }
}
