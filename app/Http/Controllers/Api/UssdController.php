<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ussd\UssdGatewayRequest;
use App\Models\UssdSession;
use App\Services\Ussd\UssdConfig;
use App\Services\Ussd\UssdRequestParser;
use App\Services\Ussd\UssdRouting;
use App\Services\Ussd\UssdVendorResolver;
use App\Services\UssdMenuService;
use App\Services\UssdSessionService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class UssdController extends Controller
{
    public function __construct(
        private readonly UssdSessionService $sessionService,
        private readonly UssdMenuService $menuService,
        private readonly UssdVendorResolver $resolver,
        private readonly UssdRequestParser $parser,
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

            $response = $session
                ? $this->continueSession($session, $text)
                : $this->beginSession($sessionId, $phoneNumber, $network, $text, $request->ip());

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

    /**
     * First request of a call: resolve the vendor, check their subscription,
     * and reserve one of their paid sessions.
     */
    private function beginSession(string $sessionId, string $phoneNumber, string $network, string $text, ?string $ip): string
    {
        $routing = $this->resolver->resolve($text);

        if (! $routing->isServiceable()) {
            $this->sessionService->logSecurityEvent(
                "USSD request refused: {$routing->reason}.",
                ['text' => $text],
                $routing->vendor?->id,
                $ip,
            );

            return $this->refusalMessage($routing);
        }

        $session = $this->sessionService->start($sessionId, $phoneNumber, $network, $routing);

        if (! $session) {
            // Lost a race for the vendor's last remaining session.
            return $this->refusalMessage(
                new UssdRouting($routing->vendor, $routing->subscription, $routing->prefixLength, UssdRouting::REASON_EXHAUSTED)
            );
        }

        // The dialled text carries no keypress yet, only the code itself.
        return $this->menuService->handle($session, '');
    }

    /**
     * Subsequent requests of an existing call.
     */
    private function continueSession(UssdSession $session, string $text): string
    {
        // A session that already reached END, or timed out, must never resume.
        // The row is retained precisely so a replayed id is distinguishable
        // from a fresh dial.
        if (! $session->isActive()) {
            return 'END This session has already ended. Please dial again.';
        }

        if ($this->sessionService->isTimedOut($session)) {
            $this->sessionService->expireSession($session);

            return 'END Your session timed out. Please dial again.';
        }

        if (! $this->sessionService->registerRequest($session)) {
            $this->sessionService->endSession($session);

            return 'END Too many requests for one session. Please dial again.';
        }

        // Re-check on every request: the subscription can expire or run out of
        // sessions mid-call.
        $subscription = $session->subscription;

        if (! $subscription || ! $subscription->isUsable()) {
            $this->sessionService->endSession($session);

            return 'END This service is temporarily unavailable. Please try again later.';
        }

        $prefixLength = (int) $session->getData('prefix_length', 0);
        $input = $this->parser->currentInput($text, $prefixLength);

        $response = $this->menuService->handle($session, $input);

        if (str_starts_with($response, 'END ')) {
            $this->sessionService->endSession($session);
        }

        return $response;
    }

    private function refusalMessage(UssdRouting $routing): string
    {
        $support = $this->config->supportNumber();
        $contact = $support ? " Contact {$support}." : '';

        return match ($routing->reason) {
            UssdRouting::REASON_NO_SUBSCRIPTION,
            UssdRouting::REASON_EXPIRED => "END This service is not currently active for this vendor.{$contact}",
            UssdRouting::REASON_EXHAUSTED => "END This vendor's USSD service is temporarily unavailable.{$contact}",
            UssdRouting::REASON_STALE_CODE => 'END This code is no longer valid. Please ask the vendor for their current code.',
            default => 'END Invalid code. Please check the number and dial again.',
        };
    }

    private function reply(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
