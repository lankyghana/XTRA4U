<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ussd\GwoSevoGatewayRequest;
use App\Models\UssdSession;
use App\Services\Ussd\GwoSevo\GwoSevoProtocol;
use App\Services\Ussd\UssdCallService;
use App\Services\Ussd\UssdConfig;
use App\Services\Ussd\UssdVendorResolver;
use App\Services\UssdSessionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * GWO SEVO USSD gateway adapter.
 *
 * Translation only: validation, vendor pinning, session-key namespacing and the
 * JSON wire format live here; menus, metering, checkout intent protection and
 * payments are the shared UssdCallService / UssdMenuService path.
 */
class GwoSevoUssdController extends Controller
{
    public function __construct(
        private readonly UssdSessionService $sessionService,
        private readonly UssdVendorResolver $resolver,
        private readonly UssdCallService $calls,
        private readonly UssdConfig $config,
    ) {}

    public function handle(GwoSevoGatewayRequest $request): JsonResponse
    {
        $gatewaySessionId = (string) $request->input('sessionID');

        if (! $this->config->isEnabled() || ! $this->config->gwoSevoEnabled()) {
            return GwoSevoProtocol::end('This service is temporarily unavailable. Please try again later.', $gatewaySessionId);
        }

        $op = (int) $request->input('ussdServiceOp');
        $text = (string) $request->input('ussdString', '');
        $network = (string) GwoSevoProtocol::networkName((string) $request->input('network'));
        $msisdn = GwoSevoProtocol::localMsisdn((string) $request->input('msisdn'));

        if ($msisdn === null) {
            return GwoSevoProtocol::end('Invalid request. Please dial again.', $gatewaySessionId);
        }

        $base = GwoSevoProtocol::internalSessionKey($gatewaySessionId, $msisdn);
        $session = null;

        try {
            $latest = $this->latestSession($base);

            $internal = $op === GwoSevoProtocol::OP_START
                ? $this->start($base, $latest, $msisdn, $network, $text, $request->ip())
                : $this->continueCall($latest, $text);

            $session = $this->latestSession($base);

            $this->sessionService->logInteraction($session?->session_id ?? $base, [
                'sessionID' => $gatewaySessionId,
                'ussdServiceOp' => $op,
                'network' => $network,
                'msisdn' => GwoSevoProtocol::maskMsisdn($msisdn),
                'ussdString' => $this->maskInput($text),
            ], $internal);

            return GwoSevoProtocol::fromInternal($internal, $gatewaySessionId);
        } catch (\Throwable $e) {
            Log::error('GWO SEVO USSD request processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'session_key' => $base,
            ]);

            // Close the session so the customer is not stuck mid-flow. The paid
            // session stays consumed - it was reserved when the call began.
            $open = $session ?? $this->latestSession($base);
            if ($open) {
                $this->sessionService->endSession($open);
            }

            return GwoSevoProtocol::end('An unexpected error occurred. Please try again later.', $gatewaySessionId);
        }
    }

    private function start(string $base, ?UssdSession $latest, string $msisdn, string $network, string $text, ?string $ip): string
    {
        // The guide does not say how the dialled code reaches us, so a start
        // that carries text is not something we can attribute to a vendor.
        if ($text !== '') {
            $this->sessionService->logSecurityEvent(
                'GWO SEVO start request refused: unexpected dial string; vendor cannot be confirmed.',
                ['gateway' => 'gwosevo', 'ussd_string_length' => strlen($text)],
                null,
                $ip,
            );

            return 'END Invalid code. Please check the number and dial again.';
        }

        if ($latest && $latest->isActive()) {
            if (! $this->sessionService->isTimedOut($latest)) {
                return $this->repeatedStart($latest);
            }

            $this->sessionService->expireSession($latest);
        }

        $routing = $this->resolver->resolveForVendor($this->config->gwoSevoVendorId());

        try {
            return $this->calls->begin(
                $this->nextSessionKey($base, $latest),
                $msisdn,
                $network,
                $routing,
                $ip,
                ['gateway' => 'gwosevo'],
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent twin of this start won the insert; its transaction
            // (including the paid-session reservation) is the only one that
            // committed, so serve this one as a repeat.
            $winner = $this->latestSession($base);

            if ($winner && $winner->isActive()) {
                return $this->repeatedStart($winner);
            }

            throw new \RuntimeException('GWO SEVO session insert conflict without an active session.');
        }
    }

    /**
     * The gateway re-sent a start for a live call (network retry). Show the
     * main menu again without reserving another paid session.
     */
    private function repeatedStart(UssdSession $session): string
    {
        $this->sessionService->resetSession($session);

        return $this->calls->continue($session->refresh(), '');
    }

    private function continueCall(?UssdSession $latest, string $text): string
    {
        if (! $latest) {
            return 'END Your session could not be found. Please dial again.';
        }

        return $this->calls->continue($latest, $text);
    }

    /**
     * The newest row for this gateway session. A gateway may reuse a short
     * session id for a later call; each reuse becomes a new generation
     * ("<key>#2", "#3", ...) because the closed rows must be retained.
     */
    private function latestSession(string $base): ?UssdSession
    {
        return UssdSession::query()
            ->where('session_id', $base)
            ->orWhere('session_id', 'like', $base.'#%')
            ->orderByDesc('id')
            ->first();
    }

    private function nextSessionKey(string $base, ?UssdSession $latest): string
    {
        if (! $latest) {
            return $base;
        }

        $generation = str_contains($latest->session_id, '#')
            ? (int) substr($latest->session_id, strrpos($latest->session_id, '#') + 1)
            : 1;

        return $base.'#'.($generation + 1);
    }

    /**
     * Customers type phone numbers into USSD prompts; keep them out of the log.
     */
    private function maskInput(string $text): string
    {
        $digits = preg_replace('/\D/', '', $text) ?? '';

        return strlen($digits) >= 9 ? GwoSevoProtocol::maskMsisdn($digits) : $text;
    }
}
