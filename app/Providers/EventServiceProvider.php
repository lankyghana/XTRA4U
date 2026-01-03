<?php

namespace App\Providers;

use App\Events\AfaRegistrationCompleted;
use App\Events\OrderCompleted;
use App\Listeners\DispatchExternalFulfillmentFromOrderCompleted;
use App\Listeners\LogRecipientNumberFromAfaRegistrationCompleted;
use App\Listeners\LogRecipientNumberFromOrderCompleted;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        OrderCompleted::class => [
            LogRecipientNumberFromOrderCompleted::class,
            DispatchExternalFulfillmentFromOrderCompleted::class,
        ],
        AfaRegistrationCompleted::class => [
            LogRecipientNumberFromAfaRegistrationCompleted::class,
        ],
    ];
}
