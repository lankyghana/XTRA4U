<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\GigshubLowBalanceAlert;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class GigshubLowBalanceWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('GigsHub low-balance webhook received', [
            'event' => $payload['event'] ?? null,
            'balance' => $payload['balance'] ?? null,
            'threshold' => $payload['threshold'] ?? null,
            'currency' => $payload['currency'] ?? null,
        ]);

        $event = $payload['event'] ?? null;
        if ($event !== 'balance.low') {
            return response()->json(['success' => false, 'error' => 'Invalid event type'], 400);
        }

        $balance = $payload['balance'] ?? null;
        $threshold = $payload['threshold'] ?? null;
        $currency = $payload['currency'] ?? 'GHS';

        if ($balance === null || $threshold === null) {
            return response()->json(['success' => false, 'error' => 'Missing balance or threshold'], 400);
        }

        $lastAlertKey = 'gigshub_last_low_balance_alert_at';
        $lastAlertTime = cache()->get($lastAlertKey);

        if ($lastAlertTime && now()->diffInSeconds($lastAlertTime) < 120) {
            Log::info('GigsHub low-balance: Duplicate suppressed (cooldown)', [
                'balance' => $balance,
                'threshold' => $threshold,
            ]);

            return response()->json(['success' => true, 'message' => 'Duplicate suppressed'], 200);
        }

        cache()->put($lastAlertKey, now(), 3600);

        $message = $payload['message'] ?? "Your wallet balance ({$currency} {$balance}) has dropped below your configured threshold of {$currency} {$threshold}.";

        GigshubLowBalanceAlert::create([
            'balance' => $balance,
            'threshold' => $threshold,
            'currency' => $currency,
            'message' => $message,
        ]);

        Log::warning('GigsHub wallet low balance alert', [
            'balance' => $balance,
            'threshold' => $threshold,
            'currency' => $currency,
            'message' => $message,
        ]);

        $this->notifyAdmins($balance, $threshold, $currency, $message);

        return response()->json(['success' => true], 200);
    }

    private function notifyAdmins(float $balance, float $threshold, string $currency, string $message): void
    {
        try {
            $notificationEmail = Setting::where('key', 'gigshub_low_balance_notification_email')->value('value');

            if ($notificationEmail) {
                Log::info('GigsHub low-balance notification would be sent to: ' . $notificationEmail);
            }
        } catch (\Exception $e) {
            Log::warning('GigsHub low-balance notification failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

