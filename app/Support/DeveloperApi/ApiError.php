<?php

namespace App\Support\DeveloperApi;

/** Stable, documented machine-readable error codes. Never rename one once published. */
final class ApiError
{
    public const INVALID_API_KEY = 'INVALID_API_KEY';

    public const API_KEY_REVOKED = 'API_KEY_REVOKED';

    public const API_KEY_EXPIRED = 'API_KEY_EXPIRED';

    public const APPLICATION_INACTIVE = 'APPLICATION_INACTIVE';

    public const VENDOR_NOT_APPROVED = 'VENDOR_NOT_APPROVED';

    public const ENVIRONMENT_MISMATCH = 'ENVIRONMENT_MISMATCH';

    public const MISSING_SCOPE = 'MISSING_SCOPE';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    public const IDEMPOTENCY_KEY_REQUIRED = 'IDEMPOTENCY_KEY_REQUIRED';

    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';

    public const OFFER_UNAVAILABLE = 'OFFER_UNAVAILABLE';

    public const SERVICE_CLOSED = 'SERVICE_CLOSED';

    public const QUOTE_NOT_FOUND = 'QUOTE_NOT_FOUND';

    public const QUOTE_EXPIRED = 'QUOTE_EXPIRED';

    public const QUOTE_ALREADY_USED = 'QUOTE_ALREADY_USED';

    public const QUOTE_PRICE_CHANGED = 'QUOTE_PRICE_CHANGED';

    public const PAYMENT_ALREADY_INITIATED = 'PAYMENT_ALREADY_INITIATED';

    public const PAYMENT_NOT_ALLOWED = 'PAYMENT_NOT_ALLOWED';

    public const PAYMENT_INITIATION_FAILED = 'PAYMENT_INITIATION_FAILED';

    public const ORDER_NOT_PAYABLE = 'ORDER_NOT_PAYABLE';

    public const SANDBOX_UNAVAILABLE = 'SANDBOX_UNAVAILABLE';

    public const PAYMENT_NOT_INITIATED = 'PAYMENT_NOT_INITIATED';

    public const SIMULATION_NOT_ALLOWED = 'SIMULATION_NOT_ALLOWED';
}
