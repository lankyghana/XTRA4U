<?php

namespace App\Http\Controllers;

use App\Services\Ai\CustomerAssistant\CustomerAssistant;
use App\Services\Ai\CustomerAssistant\CustomerAssistantLogger;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiBusyException;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\Exceptions\AiInvalidRequestException;
use App\Services\Ai\Exceptions\AiRateLimitedException;
use App\Services\Ai\Tools\ToolContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public Customer Assistant (guests, no account, no login). Both routes sit behind EnsureCustomerAssistantEnabled
 * (the feature flag), so while the flag is off neither exists.
 *
 * Trust boundaries in this class:
 *  - the visitor is anonymous: the ToolContext is built from the SERVER-side session id (hashed), never from the request
 *    body, and that hash is not treated as proof of identity or of ownership of anything;
 *  - the body may contain exactly one field, `message`; no identity, tool, context, surface, permission, history, order
 *    or payment field is read - extra fields are refused, not ignored;
 *  - it is JSON-only, so a failed validation can never redirect back with the typed text flashed into the session;
 *  - the response is never cacheable and carries a plain-text answer plus server-built links (the page renders it escaped).
 * Nothing about a conversation is stored: the message and the answer exist only for the lifetime of this request.
 */
class CustomerAssistantController extends Controller
{
    /** Hard cap on the request body in bytes: 500 characters even when each is \u-escaped (6 bytes), plus the JSON wrapper. */
    private const MAX_BODY_BYTES = 4000;

    public function show(CustomerAssistant $assistant)
    {
        return view('customer.assistant', [
            'available' => $assistant->isAvailable(),
            'maxChars' => CustomerAssistant::MAX_MESSAGE_CHARS,
        ]);
    }

    public function message(Request $request, CustomerAssistant $assistant, CustomerAssistantLogger $logger): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->fail('unsupported_media_type', 'Send the message as JSON.', 415);
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->fail('message_too_large', 'That message is too large.', 413);
        }

        $body = $request->json()->all();

        // Exactly one field. Anything else (an id, a tool name, a context, history, an order number...) is refused.
        if (array_keys($body) !== ['message'] || ! is_string($body['message'])) {
            return $this->fail('invalid_request', 'Send a single text message.', 422);
        }

        // The guest principal is the server's own session. No session means no principal: fail closed.
        if (! $request->hasSession()) {
            return $this->fail('assistant_unavailable', 'The assistant is not available right now.', 503);
        }

        try {
            $context = ToolContext::forGuest($request->session()->getId());
            $reply = $assistant->ask($context, $body['message']);
        } catch (AiException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            // Never the message or trace: only the class is recorded.
            $logger->unexpected('guest', $e::class);

            return $this->fail('assistant_error', 'The assistant could not answer right now. Please try again shortly.', 500);
        }

        return $this->noStore(response()->json([
            'ok' => true,
            'answer' => $reply->answer,
            'links' => $reply->links,
            'tools_used' => $reply->toolsUsed,
            'request_id' => $reply->requestId,
        ]));
    }

    private function failFrom(AiException $e): JsonResponse
    {
        $status = match (true) {
            $e instanceof AiRateLimitedException => 429,
            $e instanceof AiInvalidRequestException => 422,
            $e instanceof AiBusyException, $e instanceof AiBudgetExceededException => 503,
            default => 503,
        };

        $response = $this->fail($e->errorCode(), $e->userMessage(), $status);

        if ($e instanceof AiRateLimitedException && $e->retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) max(1, $e->retryAfterSeconds));
        }

        return $response;
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return $this->noStore(response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
