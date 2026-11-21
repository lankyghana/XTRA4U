<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiBusyException;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\Exceptions\AiInvalidRequestException;
use App\Services\Ai\Exceptions\AiRateLimitedException;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\VendorAssistant\VendorAssistant;
use App\Services\Ai\VendorAssistant\VendorAssistantLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Vendor Assistant inside the protected vendor dashboard. Both routes sit behind `vendor.approved` (existing `vendor`
 * guard + live approval check), so the vendor here is the signed-in, approved vendor and nobody else.
 *
 * Trust boundaries in this class:
 *  - the ToolContext is built from the AUTHENTICATED vendor of the `vendor` guard, never from the request body;
 *  - the body may contain exactly one field, `message`; no vendor, tool, context, surface, permission or history field
 *    is read;
 *  - it is JSON-only, so a failed validation can never redirect back with the typed text flashed into the session;
 *  - the response is never cacheable and carries a plain-text answer (the page renders it escaped).
 * Nothing about a conversation is stored: the message and the answer exist only for the lifetime of this request.
 */
class AssistantController extends Controller
{
    /** Hard cap on the request body in bytes (1000 characters of UTF-8 plus the JSON wrapper, with room to spare). */
    private const MAX_BODY_BYTES = 6000;

    public function show(VendorAssistant $assistant)
    {
        return view('vendor.assistant', [
            'vendor' => $this->vendor(),
            'available' => $assistant->isAvailable(),
            'maxChars' => VendorAssistant::MAX_MESSAGE_CHARS,
        ]);
    }

    public function message(Request $request, VendorAssistant $assistant, VendorAssistantLogger $logger): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->fail('unsupported_media_type', 'Send the message as JSON.', 415);
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->fail('message_too_large', 'That message is too large.', 413);
        }

        $body = $request->json()->all();

        // Exactly one field. Anything else (a vendor id, a tool name, a context, history...) is refused, not ignored.
        if (array_keys($body) !== ['message'] || ! is_string($body['message'])) {
            return $this->fail('invalid_request', 'Send a single text message.', 422);
        }

        $vendor = $this->vendor();

        try {
            $context = ToolContext::forVendor($vendor);
            $reply = $assistant->ask($context, $body['message']);
        } catch (AiException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            // Never the message or trace: only the class is recorded.
            $logger->unexpected('vendor:'.$vendor->id, $e::class);

            return $this->fail('assistant_error', 'The assistant could not answer right now. Please try again shortly.', 500);
        }

        return $this->noStore(response()->json([
            'ok' => true,
            'answer' => $reply->answer,
            'tools_used' => $reply->toolsUsed,
            'request_id' => $reply->requestId,
        ]));
    }

    private function vendor(): Vendor
    {
        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();

        return $vendor;
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
