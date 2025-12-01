<?php
namespace App\Listeners;

use App\Events\OrderCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOrderCompletionNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderCompleted $event)
    {
        // Logic to send notification (email, SMS, etc.)
        // Example: Notification::send($event->order->user, new OrderCompletedNotification($event->order));
    }
}
