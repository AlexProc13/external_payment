<?php

namespace App\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use App\Modules\Finance\Withdrawal\Withdrawal;
use App\Modules\Finance\Deposits\Deposit;
use Illuminate\Support\ServiceProvider;

class FinanceServiceProvider extends ServiceProvider implements DeferrableProvider
{
    private const DEPOSIT = 'deposit';
    private const WITHDRAWAL = 'withdrawal';

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(Deposit::class, function ($app) {
            return $this->getClassAndData($app);
        });

        $this->app->bind(Withdrawal::class, function ($app) {
            return $this->getClassAndData($app);
        });
    }

    protected function getClassAndData($app)
    {
        $request = $app['request'];
        $className = $request->params['data']['originClass'];
        return new $className($request->toArray());
    }

    public function provides()
    {
        //Deferred Providers
        return [Deposit::class, Withdrawal::class];
    }
}
