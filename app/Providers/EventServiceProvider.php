<?php

namespace App\Providers;

use App\Events\AfaRegistrationCompleted;
use App\Events\OrderCompleted;
use App\Events\ResultCheckerOrderPaid;
use App\Listeners\DispatchExternalFulfillmentFromOrderCompleted;
use App\Listeners\LogRecipientNumberFromAfaRegistrationCompleted;
use App\Listeners\LogRecipientNumberFromOrderCompleted;
use App\Listeners\SendResultCheckerDeliveryNotification;
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
        ResultCheckerOrderPaid::class => [
            SendResultCheckerDeliveryNotification::class,
        ],
    ];
}
