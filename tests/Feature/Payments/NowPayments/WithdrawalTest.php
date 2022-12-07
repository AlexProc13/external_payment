<?php

namespace Tests\Feature\Payments\NowPayments;

use Tests\TestCase;
use GuzzleHttp\Client;
use App\Helpers\Signature;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Foundation\Testing\WithFaker;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Exception\RequestException;

class WithdrawalTest extends TestCase
{
    use WithFaker;

    protected $class = \App\Modules\Finance\Withdrawal\NowPayment::class;

    protected array $headers = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->input = [
            'params' => [
                'id' => $this->faker->numberBetween(1, 200),
                'ipn' => $this->faker->uuid,
                'api' => $this->faker->uuid,
                'email' => $this->faker->email,
                'password' => $this->faker->password,
                'data' => [
                    'originClass' => $this->class,
                ]
            ],
            'user' => [
                'id' => $this->faker->numberBetween(1, 200),
                'currency' => 'USD',
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
        $response = $this->json(Request::METHOD_POST, route('makeWithdrawalExtra'), $this->input, $this->headers);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'currencies', 'is_crypto',
                ],
            ]);
    }

    public function testGetExtraDataError()
    {
        $response = $this->json(Request::METHOD_POST, route('makeWithdrawalExtra'), [], $this->headers);
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
            'address' => $this->faker->uuid,
            'amount' => $this->faker->numberBetween(100, 200),
            'currency' => $needPayIn,
            'return_url' => $this->faker->url,
        ];
        $input['callback'] = $this->faker->url;
        $input['payment'] = [
            'invoice_id' => $invoiceId,
        ];
        $uuid = $this->faker->numberBetween(100, 200);
        $expectedResult = [
            'id' => $uuid,
        ];
        $mock = new MockHandler([
            new GuzzleResponse(200, [], json_encode(['token' => $this->faker->uuid])),
            new GuzzleResponse(200, [], json_encode($expectedResult))
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $this->app->instance(Client::class, $client);

        $response = $this->json(Request::METHOD_POST, route('makeWithdrawal'), $input, $this->headers);

        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.txid', (string)$uuid)
            ->assertJsonPath('action', 'done')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'txid',
                    'action',
                    'input',
                    'output',
                ]
            ]);
        dd(1);
    }

    public function testMakeFail()
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
            'address' => $this->faker->uuid,
            'amount' => $this->faker->numberBetween(100, 200),
            'currency' => $needPayIn,
            'return_url' => $this->faker->url,
        ];
        $input['callback'] = $this->faker->url;
        $input['payment'] = [
            'invoice_id' => $invoiceId,
        ];
        $uuid = $this->faker->numberBetween(100, 200);
        $expectedResult = [
            'error' => 1,
        ];
        $mock = new MockHandler([
            new GuzzleResponse(200, [], json_encode(['token' => $this->faker->uuid])),
            new GuzzleResponse(200, [], json_encode($expectedResult))
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $this->app->instance(Client::class, $client);

        $response = $this->json(Request::METHOD_POST, route('makeWithdrawal'), $input, $this->headers);

        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.txid', null)
            ->assertJsonPath('action', 'fail')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'txid',
                    'action',
                    'input',
                    'output',
                ]
            ]);
    }

    public function testMakeUnknown()
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
            'address' => $this->faker->uuid,
            'amount' => $this->faker->numberBetween(100, 200),
            'currency' => $needPayIn,
            'return_url' => $this->faker->url,
        ];
        $input['callback'] = $this->faker->url;
        $input['payment'] = [
            'invoice_id' => $invoiceId,
        ];

        $mock = new MockHandler([
            new GuzzleResponse(200, [], json_encode(['token' => $this->faker->uuid])),
            new RequestException('Error', new GuzzleRequest('POST', 'fail'), null, null, ['total_time' => 100])
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $this->app->instance(Client::class, $client);

        $response = $this->json(Request::METHOD_POST, route('makeWithdrawal'), $input, $this->headers);

        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.txid', null)
            ->assertJsonPath('action', 'unknown')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'txid',
                    'action',
                    'input',
                ]
            ]);
    }

    public function testWebHook()
    {dd(1);
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
}
