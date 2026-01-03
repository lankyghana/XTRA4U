<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ManualQueueRunController extends Controller
{
    public function run(Request $request): JsonResponse|RedirectResponse
    {
        $adminId = optional(Auth::user())->id;
        $now = now();

        // Short-lived flag; the scheduler bridge will consume it.
        Cache::put('queue_manual_run_requested', true, now()->addMinutes(2));

        Cache::put('queue_manual_run_requested_at', $now->toIso8601String(), now()->addDays(30));
        Cache::put('queue_manual_run_requested_by', $adminId, now()->addDays(30));

        Log::info('Manual queue run requested', [
            'admin_id' => $adminId,
            'requested_at' => $now->toIso8601String(),
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Queue run requested. Scheduler will process shortly.',
                'requested_at' => $now->toIso8601String(),
            ]);
        }

        return back()->with('success', 'Queue run requested. It will start shortly.');
    }
}
