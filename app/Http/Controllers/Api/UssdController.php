<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ussd\UssdGatewayRequest;
use App\Models\UssdSession;
use App\Services\Ussd\UssdCallService;
use App\Services\Ussd\UssdConfig;
use App\Services\Ussd\UssdVendorResolver;
use App\Services\UssdSessionService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class UssdController extends Controller
{
    public function __construct(
        private readonly UssdSessionService $sessionService,
        private readonly UssdVendorResolver $resolver,
        private readonly UssdCallService $calls,
        private readonly UssdConfig $config,
    ) {}

    public function handle(UssdGatewayRequest $request): Response
    {
        if (! $this->config->isEnabled()) {
            return $this->reply('END This service is temporarily unavailable. Please try again later.');
        }

        $sessionId = (string) $request->input('sessionId');
        $phoneNumber = (string) $request->input('phoneNumber');
        $network = (string) $request->input('network', '');
        $text = (string) $request->input('text', '');

        try {
            $session = $this->sessionService->find($sessionId);

            // First request of a call: resolve the vendor from the dialled text;
            // the shared handler gates the subscription and reserves a session.
            $response = $session
                ? $this->calls->continue($session, $text)
                : $this->calls->begin(
                    $sessionId,
                    $phoneNumber,
                    $network,
                    $this->resolver->resolve($text),
                    $request->ip(),
                    ['text' => $text],
                );

            $this->sessionService->logInteraction($sessionId, $request->safe()->all(), $response);

            return $this->reply($response);
        } catch (\Throwable $e) {
            Log::error('USSD Request processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'session_id' => $sessionId,
            ]);

            // Close the session so the customer is not stuck mid-flow. The paid
            // session stays consumed — it was reserved when the call began.
            if (isset($session) && $session instanceof UssdSession) {
                $this->sessionService->endSession($session);
            }

            return $this->reply('END An unexpected error occurred. Please try again later.');
        }
    }

    private function reply(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
