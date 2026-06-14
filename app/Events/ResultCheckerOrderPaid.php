<?php

namespace App\Events;

use App\Models\ResultCheckerOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResultCheckerOrderPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public ResultCheckerOrder $order)
    {
    }
}
