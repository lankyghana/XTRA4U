<?php

namespace App\Services;

use App\Models\UssdSession;
use App\Models\UssdLog;
use Illuminate\Support\Carbon;

class UssdSessionService
{
    /**
     * Session expiration time in minutes.
     * Moolre sessions usually timeout in ~3 minutes on the network level.
     */
    const SESSION_TIMEOUT_MINUTES = 4;

    /**
     * Find an existing active session or create a new one.
     */
    public function getOrCreateSession(string $sessionId, string $phoneNumber, string $network, string $initialText = ''): UssdSession
    {
        $session = UssdSession::where('session_id', $sessionId)->first();

        if ($session) {
            // Check if the session is expired
            if ($session->updated_at->diffInMinutes(Carbon::now()) > self::SESSION_TIMEOUT_MINUTES) {
                // Technically Moolre will provide a new session ID for new sessions, 
                // but just in case, we reset an expired session if the ID is reused.
                $this->resetSession($session, $network);
            }
            return $session;
        }

        // Determine the vendor ID from the USSD extension (initial text)
        $vendorId = null;
        $inputArray = explode('*', $initialText);
        $firstInput = trim($inputArray[0]);

        if (!empty($firstInput) && is_numeric($firstInput)) {
            $vendor = \App\Models\Vendor::where('id', $firstInput)->where('is_approved', true)->first();
            if ($vendor) {
                $vendorId = $vendor->id;
            }
        }

        // Fallback to Admin vendor if no valid extension was provided
        if (!$vendorId) {
            $adminVendor = \App\Models\Vendor::where('is_approved', true)->first();
            $vendorId = $adminVendor ? $adminVendor->id : 1;
        }

        // Create a new session
        return UssdSession::create([
            'session_id' => $sessionId,
            'phone_number' => $phoneNumber,
            'network' => $network,
            'current_step' => 'MAIN_MENU',
            'data' => [
                'vendor_id' => $vendorId
            ],
        ]);
    }

    /**
     * Terminate or reset a session.
     */
    public function resetSession(UssdSession $session, string $network = null): void
    {
        $session->update([
            'current_step' => 'MAIN_MENU',
            'network' => $network ?? $session->network,
            'data' => [],
            'updated_at' => Carbon::now(), // force update timestamp
        ]);
    }

    /**
     * Delete the session completely (usually upon END response).
     */
    public function endSession(UssdSession $session): void
    {
        $session->delete();
    }

    /**
     * Log the USSD interaction for auditing/debugging.
     */
    public function logInteraction(string $sessionId, array $requestData, string $responseData): void
    {
        UssdLog::create([
            'session_id' => $sessionId,
            'request_data' => $requestData,
            'response_data' => $responseData,
        ]);
    }
}
