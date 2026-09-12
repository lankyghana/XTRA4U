<?php

namespace Tests\Unit;

use App\Support\PaymentVerificationState;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage for the normalized verification-state resolver at the
 * heart of the payment reconciliation hardening. The one rule every case
 * here defends: a verification call that did not return an authoritative
 * reading (success=false — network/timeout exception, HTTP 5xx, malformed
 * response) must NEVER resolve to FAILED, no matter what the accompanying
 * message text says.
 */
class PaymentVerificationStateTest extends TestCase
{
    public function test_explicit_success_status_is_success(): void
    {
        $this->assertSame(PaymentVerificationState::SUCCESS, PaymentVerificationState::from([
            'success' => true,
            'data' => ['status' => 'success'],
        ]));

        foreach (['successful', 'completed', 'paid', 'SUCCESS', 'Paid'] as $status) {
            $this->assertSame(PaymentVerificationState::SUCCESS, PaymentVerificationState::from([
                'success' => true,
                'data' => ['status' => $status],
            ]), "status '{$status}' should resolve to SUCCESS");
        }
    }

    public function test_explicit_terminal_failure_statuses_are_failed(): void
    {
        foreach (['failed', 'declined', 'cancelled', 'canceled', 'abandoned', 'expired', 'reversed', 'FAILED'] as $status) {
            $this->assertSame(PaymentVerificationState::FAILED, PaymentVerificationState::from([
                'success' => true,
                'data' => ['status' => $status],
            ]), "status '{$status}' should resolve to FAILED");
        }
    }

    public function test_explicit_pending_statuses_are_pending(): void
    {
        foreach (['pending', 'processing', 'initiated', 'unknown', ''] as $status) {
            $this->assertSame(PaymentVerificationState::PENDING, PaymentVerificationState::from([
                'success' => true,
                'data' => ['status' => $status],
            ]), "status '{$status}' should resolve to PENDING");
        }
    }

    public function test_unrecognized_status_is_pending_not_failed(): void
    {
        // A provider-specific status string this codebase doesn't recognize
        // must never default to FAILED — only the explicit terminal list may.
        $this->assertSame(PaymentVerificationState::PENDING, PaymentVerificationState::from([
            'success' => true,
            'data' => ['status' => 'some-unexpected-provider-status'],
        ]));
    }

    /**
     * The core regression this whole phase exists to fix: every gateway's
     * verifyPayment() collapses network/timeout exceptions into
     * {success: false, message: 'Error verifying payment.'} — a message that
     * contains the literal word "error". The old code searched message text
     * for "error"/"failed" and treated this as a failed payment. It must
     * resolve to UNKNOWN instead.
     */
    public function test_verification_call_failure_is_unknown_never_failed(): void
    {
        $this->assertSame(PaymentVerificationState::UNKNOWN, PaymentVerificationState::from([
            'success' => false,
            'message' => 'Error verifying payment.',
        ]));

        $this->assertSame(PaymentVerificationState::UNKNOWN, PaymentVerificationState::from([
            'success' => false,
            'message' => 'Failed to verify payment.',
        ]));

        // No message at all, and success missing entirely.
        $this->assertSame(PaymentVerificationState::UNKNOWN, PaymentVerificationState::from([]));
    }

    public function test_is_success_is_terminal_failure_is_unresolved_helpers(): void
    {
        $success = ['success' => true, 'data' => ['status' => 'success']];
        $failed = ['success' => true, 'data' => ['status' => 'declined']];
        $pending = ['success' => true, 'data' => ['status' => 'pending']];
        $unknown = ['success' => false, 'message' => 'Error verifying payment.'];

        $this->assertTrue(PaymentVerificationState::isSuccess($success));
        $this->assertFalse(PaymentVerificationState::isSuccess($failed));

        $this->assertTrue(PaymentVerificationState::isTerminalFailure($failed));
        $this->assertFalse(PaymentVerificationState::isTerminalFailure($unknown));
        $this->assertFalse(PaymentVerificationState::isTerminalFailure($pending));

        $this->assertTrue(PaymentVerificationState::isUnresolved($pending));
        $this->assertTrue(PaymentVerificationState::isUnresolved($unknown));
        $this->assertFalse(PaymentVerificationState::isUnresolved($success));
        $this->assertFalse(PaymentVerificationState::isUnresolved($failed));
    }
}
