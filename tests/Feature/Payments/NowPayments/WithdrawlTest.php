<?php

namespace Tests\Feature\Payments\NowPayments;

use App\Events\User\ChangeBalance;
use App\Helpers\Signature;
use App\Http\Middleware\NeedCaptcha;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sport;
use App\Models\Transaction;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\API\Payments\Helper;
use Tests\TestCase;

class WithdrawlTest extends TestCase
{
    use WithFaker;

    protected const CONFIRM_STATUS = 'confirmed';
    protected const CANCEL_STATUS = 'cancelled';
    protected const COINS_PAID_WITHDRAWAL = 'withdrawal';

    protected const STATUSES = [
        self::CONFIRM_STATUS, self::CANCEL_STATUS,
    ];

    protected string $currency = 'USD';
    protected array $headers;
    protected User $user;

    public function setUp(): void
    {
        parent::setUp();
dd(1);
        $company = factory(Company::class)->create();
        $this->platformSetUp($company);
        $sports = factory(Sport::class, 5)->create();

        factory(CompanySetting::class)->create([
            'company_id' => $company,
            'key' => 'sports',
            'value' => implode(',', $sports->pluck('id')->toArray()),
        ]);

        factory(Currency::class)->create([
            'code' => $this->currency,
            'symbol' => $this->currency,
            'exact_value' => 1,
            'value' => 1,
        ]);

        $password = $this->faker->password;
        $balance = mt_rand(2000, 10000);
        $this->user = factory(User::class)->create([
            'password' => Hash::make($password),
            'company_id' => $company,
            'currency' => $this->currency,
            'balance' => $balance,
            'withdrawal' => $balance,
        ]);

        $this->headers = [
            'platform-id' => $company->uuid,
        ];

        factory(CompanySetting::class)->create([
            'company_id' => $this->user->company_id,
            'key' => 'auth_way',
            'value' => 'email',
        ]);

        $this->withoutMiddleware(NeedCaptcha::class);
        $loginResponse = $this->json(Request::METHOD_POST, route('api.users.authorize'), [
            'user_id' => $this->user->email,
            'password' => $password,
        ], $this->headers);

        $token = json_decode($loginResponse->getContent())->data->token;

        $this->headers = array_merge($this->headers, [
            'Authorization' => "Bearer {$token}",
        ]);

        $paymentHash = getPaymentHash('CoinsPaid', config('finance.payments.types.withdrawal'), 'CoinsPaid');
        $this->provider = Helper::setProvider(config('finance.payments.types.withdrawal'), $paymentHash, $company);
    }

    public function testGetListProviders()
    {
        $response = $this->json(Request::METHOD_GET, route('api.withdrawal.getProviders'), [], $this->headers);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure([
                'status',
                'msg',
                'data' => [
                    [
                        'id',
                        'name',
                        'code',
                        'min_limit',
                        'max_limit',
                        'currency',
                        'image',
                    ]
                ],
            ]);
    }

    public function testGetListProvidersError()
    {
        $headers = $this->headers;
        unset($headers['Authorization']);

        $response = $this->json(Request::METHOD_GET, route('api.withdrawal.getProviders'), [], $headers);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('error_code', 1010);
    }

    public function testOrder()
    {
        $amount = $this->faker->numberBetween(100, 200);
        $transactionTypes = config('additional.transactions.type');
        $financeData = config('finance.payments');

        $invoice = factory(Invoice::class)->create();

        $incomeData = [
            'type' => $this->provider->id,
            'code' => $this->provider->id,
            'return_url' => $this->faker->url,
            'id' => $this->faker->uuid,
            'address' => $this->faker->uuid,
            'currency' => $this->currency,
            'tag' => null,
            'amount' => $amount,
        ];

        $expectedResult = [
            'data' => [
                'id' => $this->faker->uuid,
            ],
        ];

        Event::fake();

        $mock = new MockHandler([new GuzzleResponse(201, [], json_encode($expectedResult))]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $this->app->instance(Client::class, $client);

        $response = $this->json(Request::METHOD_POST, route('api.withdrawal.order'), $incomeData, $this->headers);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure([
                'status',
                'msg',
                'data' => [
                    'redirect_url',
                ],
            ]);

        $this->assertDatabaseHas('invoices', [
            'uuid' => $expectedResult['data']['id'],
            'user_id' => $this->user->id,
            'type' => $this->provider->id,
            'category' => $financeData['invoiceType']['withdrawal'],
            'status' => $financeData['statuses']['pending'],
        ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $this->user->id,
            'type' => $financeData['types']['withdrawal'],
            'provider' => $this->provider->name,
            'initiator_id' => $this->provider->id,
            'value' => -1 * $incomeData['amount'],
            'status' => $financeData['statuses']['pending'],
        ]);

        $this->assertDatabaseHas('transactions', [
            'type' => $transactionTypes['frozen'],
            'user_id' => $this->user->id,
            'sum' => -1 * $incomeData['amount'],
        ]);

        Event::assertDispatched(ChangeBalance::class, function ($e) {
            return $this->user->id == $e->getUserId();
        });
    }

    public function testOrderErrors()
    {
        $amount = $this->faker->numberBetween(100, 200);
        $incomeData = [
            'type' => 'none_correct_type',
            'code' => $this->provider->id,
            'return_url' => $this->faker->url,
            'id' => $this->faker->uuid,
            'address' => $this->faker->uuid,
            'currency' => $this->currency,
            'tag' => null,
            'amount' => $amount,
        ];

        $response = $this->json(Request::METHOD_POST, route('api.payments.order'), $incomeData, $this->headers);
        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('error_code', 1005);
    }

    public function testWebHook()
    {
        $amount = $this->faker->numberBetween(100, 200);
        $transactionTypes = config('additional.transactions.type');
        $financeData = config('finance.payments');

        $uuid = $this->faker->uuid;
        $invoice = factory(Invoice::class)->create([
            'uuid' => $uuid,
            'user_id' => $this->user->id,
            'type' => $this->provider->id,
            'category' => $financeData['invoiceType']['withdrawal'],
            'status' => $financeData['statuses']['pending'],
            'origin' => json_encode([]),
            'data' => json_encode([]),
            'response' => json_encode([]),
        ]);
        $transaction = Transaction::create([
            'type' => config('additional.transactions.type.frozen'),
            'user_id' => $this->user->id,
            'sum' => $amount,
            'comment' => 'withdrawal by ' . $this->provider->id,
            'extra' => json_encode(['balance' => $this->user->balance]),
        ]);
        $payment = Payment::create([
            'user_id' => $this->user->id,
            'type' => config('finance.payments.types.withdrawal'),
            'value' => $amount,
            'provider' => $this->provider->name,
            'invoice_id' => $invoice->id,
            'transaction_id' => $transaction->id,
            'status' => config('finance.payments.statuses.pending'),
            'initiator_id' => $this->provider->id,
            'extra' => json_encode(['balance' => $this->user->balance]),
        ]);

        $incomeData = [
            'id' => $uuid,
            'type' => self::COINS_PAID_WITHDRAWAL,
            'status' => self::CONFIRM_STATUS,
            'foreign_id' => $invoice->id,
            'currency_sent' => [
                'amount' => $amount,
            ],
            'error' => '',
        ];
        DB::table('transaction_relations')->insert([
            'type' => config('additional.transactionRelations.payment'),
            'transaction_id' => $transaction->id,
            'related_id' => $payment->id,
        ]);
        Event::fake();

        $response = $this->json(Request::METHOD_POST, route('api.payments.generalWebHooks', [
            'payment' => $this->provider->gateway_id,
            'company' => $this->user->company_id,
            'group' => $this->provider->group,
        ]), $incomeData, [
            'X-Processing-Signature' => Signature::makeBySha512(
                json_encode($incomeData),
                $this->provider->depends['secret_key']
            ),
        ]);

        $response->assertStatus(Response::HTTP_OK);

        $this->assertDatabaseHas('invoices', [
            'uuid' => $incomeData['id'],
            'user_id' => $this->user->id,
            'type' => $this->provider->id,
            'category' => $financeData['invoiceType']['withdrawal'],
            'status' => $financeData['statuses']['completed'],
        ]);

        $this->assertDatabaseHas('transactions', [
            'type' => $transactionTypes['withdrawal'],
            'user_id' => $this->user->id,
            'sum' => $amount,
        ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $this->user->id,
            'type' => $financeData['types']['withdrawal'],
            'provider' => $this->provider->name,
            'value' => $amount,
            'initiator_id' => $this->provider->id,
            'status' => $financeData['statuses']['completed'],
        ]);
    }

    public function testWebHookErrors()
    {
        $amount = $this->faker->numberBetween(100, 200);
        $transactionTypes = config('additional.transactions.type');
        $financeData = config('finance.payments');

        $uuid = $this->faker->uuid;
        $invoice = factory(Invoice::class)->create([
            'uuid' => $uuid,
            'user_id' => $this->user->id,
            'type' => $this->provider->id,
            'category' => $financeData['invoiceType']['withdrawal'],
            'status' => $financeData['statuses']['pending'],
            'origin' => json_encode([]),
            'data' => json_encode([]),
            'response' => json_encode([]),
        ]);
        $transaction = Transaction::create([
            'type' => config('additional.transactions.type.frozen'),
            'user_id' => $this->user->id,
            'sum' => $amount,
            'comment' => 'withdrawal by ' . $this->provider->id,
            'extra' => json_encode(['balance' => $this->user->balance]),
        ]);
        $payment = Payment::create([
            'user_id' => $this->user->id,
            'type' => config('finance.payments.types.withdrawal'),
            'value' => $amount,
            'provider' => $this->provider->name,
            'invoice_id' => $invoice->id,
            'transaction_id' => $transaction->id,
            'status' => config('finance.payments.statuses.pending'),
            'initiator_id' => $this->provider->id,
            'extra' => json_encode(['balance' => $this->user->balance]),
        ]);

        $incomeData = [
            'id' => $uuid,
            'type' => self::COINS_PAID_WITHDRAWAL,
            'status' => self::CANCEL_STATUS,
            'foreign_id' => $invoice->id,
            'currency_sent' => [
                'amount' => $amount,
            ],
            'error' => '',
        ];
        DB::table('transaction_relations')->insert([
            'type' => config('additional.transactionRelations.payment'),
            'transaction_id' => $transaction->id,
            'related_id' => $payment->id,
        ]);
        Event::fake();

        $response = $this->json(Request::METHOD_POST, route('api.payments.generalWebHooks', [
            'payment' => $this->provider->gateway_id,
            'company' => $this->user->company_id,
            'group' => $this->provider->group,
        ]), $incomeData, [
            'X-Processing-Signature' => Signature::makeBySha512(
                json_encode($incomeData),
                $this->provider->depends['secret_key']
            ),
        ]);

        $response->assertStatus(Response::HTTP_OK);

        $this->assertDatabaseHas('invoices', [
            'uuid' => $incomeData['id'],
            'user_id' => $this->user->id,
            'type' => $this->provider->id,
            'category' => $financeData['invoiceType']['withdrawal'],
            'status' => $financeData['statuses']['error'],
        ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $this->user->id,
            'type' => $financeData['types']['withdrawal'],
            'provider' => $this->provider->name,
            'value' => $amount,
            'initiator_id' => $this->provider->id,
            'status' => $financeData['statuses']['error'],
        ]);
    }
}
