<?php

namespace App\Services\Ussd;

use App\Models\UssdSession;
use App\Services\UssdMenuService;
use App\Services\UssdSessionService;

/**
 * Gateway-independent USSD call handling, shared by every gateway controller.
 *
 * Works entirely in the internal "CON ..." / "END ..." vocabulary; each gateway
 * controller translates that to and from its own wire format.
 */
class UssdCallService
{
    public function __construct(
        private readonly UssdSessionService $sessionService,
        private readonly UssdMenuService $menuService,
        private readonly UssdRequestParser $parser,
        private readonly UssdConfig $config,
    ) {}

    /**
     * First request of a call: refuse unserviceable routing, otherwise reserve
     * one of the vendor's paid sessions and show the main menu.
     *
     * @param  array<string, mixed>  $auditContext  safe (already-masked) context for refusals
     */
    public function begin(
        string $sessionId,
        string $phoneNumber,
        string $network,
        UssdRouting $routing,
        ?string $ip,
        array $auditContext = [],
    ): string {
        if (! $routing->isServiceable()) {
            $this->sessionService->logSecurityEvent(
                "USSD request refused: {$routing->reason}.",
                $auditContext,
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
    public function continue(UssdSession $session, string $text): string
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

    public function refusalMessage(UssdRouting $routing): string
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
}
