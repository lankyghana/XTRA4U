<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

class PrunePurchaseTokens
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->session()->has('purchase.tokens')) {
            $tokens = $request->session()->get('purchase.tokens', []);
            $now = Carbon::now();

            foreach ($tokens as $key => $details) {
                $expiresAt = isset($details['expires_at']) ? Carbon::parse($details['expires_at']) : null;

                if ($expiresAt && $now->greaterThan($expiresAt)) {
                    unset($tokens[$key]);
                }
            }

            $request->session()->put('purchase.tokens', $tokens);
        }

        return $next($request);
    }
}
