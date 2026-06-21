<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\UssdSessionService;
use App\Services\UssdMenuService;
use Illuminate\Support\Facades\Log;

class UssdController extends Controller
{
    protected UssdSessionService $sessionService;
    protected UssdMenuService $menuService;

    public function __construct(UssdSessionService $sessionService, UssdMenuService $menuService)
    {
        $this->sessionService = $sessionService;
        $this->menuService = $menuService;
    }

    /**
     * Handle incoming USSD request from Moolre gateway.
     */
    public function handle(Request $request)
    {
        // Extract standard Moolre USSD payload parameters
        $sessionId = $request->input('sessionId', '');
        $phoneNumber = $request->input('phoneNumber', '');
        $network = $request->input('network', '');
        $serviceCode = $request->input('serviceCode', '');
        $text = $request->input('text', '');

        // If sessionId or phoneNumber is missing, we cannot process the request
        if (empty($sessionId) || empty($phoneNumber)) {
            Log::warning('USSD Request missing sessionId or phoneNumber', $request->all());
            return response("END Invalid session details.", 200)
                    ->header('Content-Type', 'text/plain');
        }

        try {
            // Get or create the session
            $session = $this->sessionService->getOrCreateSession($sessionId, $phoneNumber, $network, $text);

            // Process the input and get the next menu response
            $responseString = $this->menuService->handle($session, $text);

            // If the response indicates the session is ending, clean it up
            if (str_starts_with($responseString, 'END ')) {
                $this->sessionService->endSession($session);
            }

            // Log the interaction
            $this->sessionService->logInteraction($sessionId, $request->all(), $responseString);

            // Return plaintext response as required by Moolre
            return response($responseString, 200)
                    ->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('USSD Request processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            // Attempt to clean up session on fatal error to prevent being stuck
            if (isset($session)) {
                $this->sessionService->endSession($session);
            }

            return response("END An unexpected error occurred. Please try again later.", 200)
                    ->header('Content-Type', 'text/plain');
        }
    }
}
