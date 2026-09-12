<?php

namespace App\Console\Commands;

use App\Models\PaymentGatewayConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Isolated, non-production diagnostic for Payaza's SANDBOX payout API.
 *
 * This exists solely to learn and verify Payaza's real sandbox request/
 * response shapes before PayazaPayoutService is ever written. It does NOT
 * touch VendorWithdrawal, wallet balances, GatewayManager's payout
 * resolution, or supports_payout. Nothing here is reachable from any
 * production code path — this command is the only caller of the request
 * logic below, and it exists nowhere else.
 *
 * Every credential value (public key, secret key, transaction PIN,
 * Authorization header, X-Payaza-Signature) is redacted from all output
 * and all logging. Only structural/non-sensitive fields are ever printed.
 */
class PayazaPayoutDiagnostic extends Command
{
    protected $signature = 'payaza:payout-diagnostic
                            {--bank-code= : Payaza bank_code for the test recipient network, CONFIRMED with Payaza (or observed in sandbox) — never guessed}
                            {--phone= : Sandbox/test recipient phone number to pay out to}
                            {--account-name=Sandbox Test Recipient : Recipient account name to send}
                            {--amount=1 : Smallest sensible sandbox GHS amount for this diagnostic}
                            {--reference= : Re-check status of a previously-run diagnostic reference instead of initiating a new payout}
                            {--force : Required in addition to all other guards before any request is sent}';

    protected $description = 'Isolated, sandbox-only diagnostic for Payaza payout initiation/status — never touches VendorWithdrawal or wallets';

    private const INITIATE_PATH = '/payout-receptor/payout';

    private const STATUS_PATH = '/payaza-account/api/v1/mainaccounts/transaction/status';

    private const ACCOUNT_ENQUIRY_PATH = '/payaza-account/api/v1/mainaccounts/merchant/enquiry/main';

    // All three documented payout endpoints hang off this exact prefix —
    // matches the convention already used by the Payaza COLLECTION config's
    // default_config (base_url = https://api.payaza.africa/live). "/live" is
    // part of the path for every environment; X-TenantID (not the URL) is
    // what actually switches test vs. live. Never guess a different origin.
    private const EXPECTED_BASE_URL_SUFFIX = '/live';

    private const EXPECTED_HOST_FRAGMENT = 'payaza.africa';

    public function handle(): int
    {
        $this->warn('=== Payaza Payout Diagnostic (sandbox only) ===');

        // ---------------------------------------------------------- Guard 1: environment
        if (app()->environment('production')) {
            $this->error('ABORT: refusing to run in a production environment (APP_ENV=production).');

            return self::FAILURE;
        }

        // ---------------------------------------------------------- Guard 2: config exists
        $config = PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_PAYAZA)
            ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)
            ->first();

        if (! $config) {
            $this->error('ABORT: no Payaza payout gateway configuration exists yet. Configure it in Admin → Payment Gateways first.');

            return self::FAILURE;
        }

        // ---------------------------------------------------------- Guard 3: sandbox only
        if ($config->environment !== PaymentGatewayConfig::ENV_SANDBOX) {
            $this->error("ABORT: Payaza payout config environment is '{$config->environment}', not sandbox. This diagnostic refuses anything but sandbox.");

            return self::FAILURE;
        }

        // ---------------------------------------------------------- Guard 4: never activate anything
        // This diagnostic never sets these, but re-assert loudly if something
        // else ever did — running the diagnostic must not be read as "this is safe to go live".
        if ($config->is_active || $config->supports_payout) {
            $this->error('ABORT: this Payaza payout config is active and/or supports_payout is true. That should never happen before PayazaPayoutService exists — refusing to proceed until that is corrected.');

            return self::FAILURE;
        }

        // ---------------------------------------------------------- Guard 5: credentials present + PIN well-formed
        $publicKey = trim((string) $config->getConfig('public_key', ''));
        $pin = trim((string) $config->getConfig('transaction_pin', ''));
        $baseUrl = rtrim((string) $config->getConfig('base_url', ''), '/');

        if ($publicKey === '') {
            $this->error('ABORT: public_key is missing from the Payaza payout configuration.');

            return self::FAILURE;
        }

        if ($pin === '') {
            $this->error('ABORT: transaction_pin is missing from the Payaza payout configuration.');

            return self::FAILURE;
        }

        if (! PaymentGatewayConfig::isValidPayazaTransactionPin($pin)) {
            $this->error('ABORT: transaction_pin is present but malformed (must be 6 digits, no repeated digits, not a sequential run).');

            return self::FAILURE;
        }

        if ($baseUrl === '') {
            $this->error('ABORT: base_url is missing from the Payaza payout configuration.');

            return self::FAILURE;
        }

        // ---------------------------------------------------------- Guard 5b: base_url shape
        // Payaza's documented payout/status/account-enquiry endpoints all sit
        // under https://api.payaza.africa/live/... — never guessed here, only
        // checked against exactly what the current official docs show. This
        // does NOT auto-correct or append anything (that would risk producing
        // /live/live/) — a wrong value must be fixed in the admin config, not
        // silently patched by this command.
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || ! str_contains((string) parse_url($baseUrl, PHP_URL_HOST), self::EXPECTED_HOST_FRAGMENT)
            || ! str_ends_with($baseUrl, self::EXPECTED_BASE_URL_SUFFIX)
        ) {
            $this->error("ABORT: base_url ('host: {$this->hostOnly($baseUrl)}') does not look like a real Payaza API endpoint.");
            $this->line('Per current Payaza docs, every payout/status/account-enquiry endpoint sits under exactly: https://api.payaza.africa/live');
            $this->line('Update the Payaza PAYOUT gateway config\'s base_url to that value (same convention already used by the Payaza collection config) and re-run.');

            return self::FAILURE;
        }

        $this->info('Guards passed: local/testing environment, sandbox config, credentials present, PIN well-formed, base_url shape valid.');
        $this->line("Base URL host in use: {$this->hostOnly($baseUrl)}");
        $this->line('X-Payaza-Signature: NOT IMPLEMENTED — whether this sandbox account is activated for signed transfer requests is not established; using only the confirmed Authorization + X-TenantID auth mode.');

        // ---------------------------------------------------------- X-TenantID is always "test" here
        // There is no --live flag on this command and never will be — the
        // tenant header is hardcoded, not derived from any option.
        $tenantId = 'test';
        $this->line('X-TenantID: '.$tenantId.' (hardcoded — this command has no path to "live")');

        // ---------------------------------------------------------- Reference handling
        $statusOnly = $this->option('reference') !== null;
        $reference = $statusOnly
            ? (string) $this->option('reference')
            : 'PAYAZA-DIAG-'.strtoupper(Str::random(10)).'-'.now()->format('YmdHis');

        $this->info("Diagnostic transaction_reference: {$reference}");

        if ($statusOnly) {
            $this->warn('--reference supplied: skipping initiation entirely, checking status only.');

            return $this->runStatusLookup($config, $baseUrl, $publicKey, $tenantId, $reference) ? self::SUCCESS : self::FAILURE;
        }

        // ---------------------------------------------------------- Guard 5c: active GHS account_reference
        // service_payload.account_reference must be Payaza's own account
        // reference for the payout currency — NOT this diagnostic's
        // transaction_reference. Discovered read-only via the account
        // enquiry endpoint rather than typed/guessed.
        $accountReference = $this->discoverGhsAccountReference($baseUrl, $publicKey, $tenantId);
        if ($accountReference === null) {
            $this->error('STOP: could not confirm an active GHS Payaza account_reference. Refusing to proceed without one.');

            return self::FAILURE;
        }

        // ---------------------------------------------------------- Guard 6: bank_code required, never guessed
        $bankCode = trim((string) $this->option('bank-code'));
        if ($bankCode === '') {
            $this->error('STOP: no --bank-code supplied. Payaza\'s Ghana mobile-money bank_code values (MTN/Telecel/AirtelTigo) are not documented anywhere Payaza publishes, and this command will never guess or invent one.');
            $this->line('This remains the blocker: obtain the confirmed bank_code from Payaza support (integrationsupport@payaza.africa) or from the sandbox dashboard, then re-run with --bank-code=<value>.');

            return self::FAILURE;
        }

        $phone = trim((string) $this->option('phone'));
        if ($phone === '') {
            $this->error('ABORT: no --phone supplied. Provide a sandbox/test recipient phone number — never a real vendor\'s number.');

            return self::FAILURE;
        }

        // ---------------------------------------------------------- Guard 7: explicit confirmation
        if (! $this->option('force')) {
            $this->error('ABORT: this sends one real sandbox-mode HTTP request to Payaza. Re-run with --force to confirm you intend to do that now.');

            return self::FAILURE;
        }

        $amount = (float) $this->option('amount');
        if ($amount <= 0) {
            $this->error('ABORT: --amount must be a positive number.');

            return self::FAILURE;
        }

        $accountName = (string) $this->option('account-name');

        $this->newLine();
        $this->warn('About to send ONE payout initiation request:');
        $this->table(['Field', 'Value'], [
            ['transaction_reference', $reference],
            ['currency', 'GHS'],
            ['country', 'GHA (confirmed by current Payaza docs — still compared against the actual response)'],
            ['amount (both payout_amount and credit_amount)', number_format($amount, 2)],
            ['bank_code', $bankCode],
            ['recipient phone', $this->maskPhone($phone)],
            ['account_name', $accountName],
        ]);

        return $this->initiatePayout($config, $baseUrl, $publicKey, $pin, $tenantId, $reference, $accountReference, $amount, $bankCode, $phone, $accountName)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Send exactly ONE payout initiation request. No retries — this is a
     * mutating financial call; retrying after an ambiguous failure risks a
     * duplicate real transfer even in sandbox. Any ambiguity falls straight
     * through to a single status lookup with the same reference.
     */
    private function initiatePayout(
        PaymentGatewayConfig $config,
        string $baseUrl,
        string $publicKey,
        string $pin,
        string $tenantId,
        string $reference,
        string $accountReference,
        float $amount,
        string $bankCode,
        string $phone,
        string $accountName
    ): bool {
        $payload = [
            'transaction_type' => 'mobile_money',
            'service_payload' => [
                'payout_amount' => $amount,
                'transaction_pin' => $pin,
                'account_reference' => $accountReference,
                'currency' => 'GHS',
                'country' => 'GHA', // confirmed by current Payaza docs
            ],
            'payout_beneficiaries' => [[
                'credit_amount' => $amount,
                'account_number' => $phone,
                'account_name' => $accountName,
                'bank_code' => $bankCode,
                'transaction_reference' => $reference,
                'narration' => 'XTRA4U Payaza payout diagnostic (sandbox)',
            ]],
            'sender' => [
                'sender_name' => 'XTRA4U Diagnostic',
                'sender_phone_number' => $phone,
                'sender_address' => 'N/A - sandbox diagnostic',
            ],
        ];

        Log::info('Payaza payout diagnostic: sending initiation request', [
            'reference' => $reference,
            'amount' => $amount,
            'bank_code' => $bankCode,
            // Never logged: transaction_pin, public_key, Authorization header.
        ]);

        $status = null;
        $body = null;
        $networkFailed = false;

        try {
            // Deliberately NO ->retry(...) — a single mutating POST only.
            $response = Http::withHeaders([
                'Authorization' => 'Payaza '.base64_encode($publicKey),
                'X-TenantID' => $tenantId,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post($baseUrl.self::INITIATE_PATH, $payload);

            $status = $response->status();
            $body = $response->json();
        } catch (\Throwable $e) {
            // Timeout / connection loss / any transport-level failure. This is
            // NOT a payout failure — it is unknown. Never send another POST here.
            $networkFailed = true;
            $this->error('Initiation request experienced a network-level failure (this is NOT a confirmed failure): '.$this->sanitizeExceptionMessage($e));

            Log::warning('Payaza payout diagnostic: initiation network failure', [
                'reference' => $reference,
                'error_class' => get_class($e),
            ]);
        }

        if (! $networkFailed) {
            $this->newLine();
            $this->info("HTTP status: {$status}");
            $this->line('Sanitized initiation response:');
            $this->printSanitized($body);

            $classification = $this->classifyInitiationResult($status, $body);
            $this->line("Classification: {$classification}");

            if ($classification === 'CONFIRMED_SUCCESS' || $classification === 'CONFIRMED_FAILURE') {
                $this->newLine();
                $this->info('Result is unambiguous — status lookup is optional but still recommended to confirm the status-endpoint shape. Running it now.');
            } else {
                $this->newLine();
                $this->warn('Result is ambiguous or pending — moving to status lookup. No second initiation will be sent.');
            }
        } else {
            $this->newLine();
            $this->warn('Moving to status lookup with the same reference, as required after a network-level ambiguity. No second initiation will be sent.');
        }

        $this->newLine();

        return $this->runStatusLookup($config, $baseUrl, $publicKey, $tenantId, $reference);
    }

    /**
     * Read-only pre-flight: find Payaza's own account_reference for the GHS
     * account, and confirm it's active where the response says so. Never
     * mutates anything. Returns null (and prints why) on any failure to
     * confirm — the caller must STOP rather than fall back to a guessed
     * value.
     *
     * The exact response shape is not yet confirmed from documentation, so
     * this searches a few plausible wrapper keys defensively; the raw
     * top-level key names are always shown via printSanitized() so a human
     * can see the real shape if this guess doesn't match.
     */
    private function discoverGhsAccountReference(string $baseUrl, string $publicKey, string $tenantId): ?string
    {
        $this->info('Running account enquiry (read-only) to find the active GHS account_reference...');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Payaza '.base64_encode($publicKey),
                'X-TenantID' => $tenantId,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                // Read-only GET — safe to retry on transient failure.
                // throw:false is required: retry()'s default (throw:true) turns
                // any non-2xx into a thrown RequestException, which would get
                // mis-classified below as an ambiguous network failure instead
                // of the clean, definitive provider response it actually is.
                ->retry(2, 300, throw: false)
                ->get($baseUrl.self::ACCOUNT_ENQUIRY_PATH);
        } catch (\Throwable $e) {
            $this->error('Account enquiry failed at the network level: '.$this->sanitizeExceptionMessage($e));

            return null;
        }

        $this->line("Account enquiry HTTP status: {$response->status()}");

        if (! $response->successful()) {
            $this->error('Account enquiry did not return a successful response — cannot confirm a GHS account_reference.');
            $this->line('Sanitized account enquiry response (top-level shape only):');
            $this->printSanitized($response->json());

            return null;
        }

        $body = $response->json();
        $this->line('Sanitized account enquiry response (top-level shape only — no balances/PII):');
        $this->printSanitizedAccountEnquiry($body);

        $accounts = $this->extractAccountList($body);

        $ghsAccount = collect($accounts)->first(
            fn ($account) => is_array($account) && strtoupper((string) ($account['currency'] ?? '')) === 'GHS'
        );

        if (! $ghsAccount) {
            $this->error('No GHS entry was found in the account enquiry response.');

            return null;
        }

        $reference = $ghsAccount['payazaAccountReference'] ?? null;
        if (! is_string($reference) || trim($reference) === '') {
            $this->error('A GHS account entry was found, but it has no payazaAccountReference field.');

            return null;
        }

        $statusValue = $ghsAccount['status'] ?? $ghsAccount['accountStatus'] ?? null;
        if ($statusValue !== null && strtoupper((string) $statusValue) !== 'ACTIVE') {
            $this->error("A GHS account_reference was found, but its status is '{$statusValue}', not ACTIVE.");

            return null;
        }

        $this->info($statusValue !== null
            ? 'GHS account_reference confirmed and ACTIVE.'
            : 'GHS account_reference found, but no status field was present to confirm ACTIVE — proceeding cautiously since one was requested.');

        // Deliberately not printed even though it's found: this is an
        // account identifier, not a per-call secret, but "don't print
        // unnecessary account details" errs on the side of not echoing it.
        return trim($reference);
    }

    /**
     * Best-effort extraction of an account list from an unconfirmed response
     * shape. Tries the body itself first, then a few plausible wrapper keys.
     */
    private function extractAccountList($body): array
    {
        if (! is_array($body)) {
            return [];
        }

        if (isset($body[0]) && is_array($body[0]) && array_key_exists('currency', $body[0])) {
            return $body;
        }

        foreach (['data', 'accounts', 'response_content', 'content'] as $key) {
            $candidate = $body[$key] ?? null;
            if (! is_array($candidate)) {
                continue;
            }

            if (isset($candidate[0]) && is_array($candidate[0])) {
                return $candidate;
            }

            foreach (['accounts', 'data'] as $innerKey) {
                if (isset($candidate[$innerKey]) && is_array($candidate[$innerKey])) {
                    return $candidate[$innerKey];
                }
            }
        }

        return [];
    }

    /**
     * Stricter sanitizer for the account enquiry response specifically —
     * never shows balance-like or free-text account detail fields, even
     * ones that might otherwise pass the generic printSanitized() whitelist.
     */
    private function printSanitizedAccountEnquiry(?array $body): void
    {
        if ($body === null) {
            $this->line('  (response body was not valid JSON, or empty)');

            return;
        }

        $neverShow = ['balance', 'available_balance', 'ledger_balance', 'account_number', 'accountnumber', 'bvn', 'name', 'account_name'];

        foreach ($body as $key => $value) {
            $keyLower = strtolower((string) $key);

            if (str_contains($keyLower, 'pin') || str_contains($keyLower, 'secret')
                || str_contains($keyLower, 'signature') || str_contains($keyLower, 'authorization')
                || str_contains($keyLower, 'token') || str_contains($keyLower, 'reference')
                || in_array($keyLower, $neverShow, true)) {
                $this->line("  {$key}: <redacted>");

                continue;
            }

            if (is_array($value)) {
                $this->line("  {$key}: <object/array present — keys: ".implode(', ', array_keys($value)).'>');

                continue;
            }

            if (in_array($keyLower, ['currency', 'status', 'accountstatus', 'country'], true)) {
                $this->line("  {$key}: ".json_encode($value));

                continue;
            }

            $this->line("  {$key}: <value present, type=".gettype($value).', redacted>');
        }
    }

    /**
     * Query status for the given reference. Safe to call repeatedly — GET,
     * read-only, no side effects on Payaza's side (as far as documented).
     */
    private function runStatusLookup(
        PaymentGatewayConfig $config,
        string $baseUrl,
        string $publicKey,
        string $tenantId,
        string $reference
    ): bool {
        $this->info("Querying status for reference: {$reference}");

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Payaza '.base64_encode($publicKey),
                'X-TenantID' => $tenantId,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                // A read-only GET is safe to retry a couple of times on transient failure.
                // throw:false — see discoverGhsAccountReference() for why this matters.
                ->retry(2, 300, throw: false)
                ->get($baseUrl.self::STATUS_PATH, ['transaction_reference' => $reference]);

            $status = $response->status();
            $body = $response->json();
        } catch (\Throwable $e) {
            $this->error('Status lookup itself failed at the network level: '.$this->sanitizeExceptionMessage($e));
            $this->line('Classification: UNKNOWN (status lookup could not be confirmed — do not treat this as failure).');

            Log::warning('Payaza payout diagnostic: status lookup network failure', [
                'reference' => $reference,
                'error_class' => get_class($e),
            ]);

            return false;
        }

        $this->newLine();
        $this->info("HTTP status: {$status}");
        $this->line('Sanitized status response:');
        $this->printSanitized($body);

        $classification = $this->classifyStatusResult($status, $body);
        $this->line("Classification: {$classification}");

        return true;
    }

    private function classifyInitiationResult(?int $httpStatus, ?array $body): string
    {
        if ($httpStatus === null) {
            return 'UNKNOWN';
        }

        $txStatus = strtoupper((string) ($body['transaction_status'] ?? $body['status'] ?? ''));

        if ($httpStatus >= 500) {
            return 'UNKNOWN';
        }

        return match ($txStatus) {
            'NIP_SUCCESS' => 'CONFIRMED_SUCCESS',
            'NIP_FAILURE' => 'CONFIRMED_FAILURE',
            'TRANSACTION_INITIATED', 'NIP_PENDING', 'ESCROW_SUCCESS' => 'PENDING',
            default => $httpStatus >= 200 && $httpStatus < 300 ? 'PENDING' : 'UNKNOWN',
        };
    }

    private function classifyStatusResult(?int $httpStatus, ?array $body): string
    {
        if ($httpStatus === null || $httpStatus >= 500) {
            return 'UNKNOWN';
        }

        $txStatus = strtoupper((string) ($body['transaction_status'] ?? $body['status'] ?? ''));

        return match ($txStatus) {
            'NIP_SUCCESS' => 'CONFIRMED_SUCCESS',
            'NIP_FAILURE' => 'CONFIRMED_FAILURE',
            'TRANSACTION_INITIATED', 'NIP_PENDING', 'ESCROW_SUCCESS' => 'PENDING',
            default => 'UNKNOWN',
        };
    }

    /**
     * Print only a whitelisted, non-sensitive view of a Payaza response:
     * top-level key NAMES always; a small set of known-safe keys with their
     * actual value; everything else redacted by type only.
     */
    private function printSanitized(?array $body): void
    {
        if ($body === null) {
            $this->line('  (response body was not valid JSON, or empty)');

            return;
        }

        $safeToShowValue = [
            'response_code', 'response_message', 'message', 'status', 'transaction_status',
            'transaction_reference', 'currency', 'country', 'transaction_id', 'id',
            'reference_id', 'provider_reference', 'is_reversed', 'success',
        ];

        foreach ($body as $key => $value) {
            $keyLower = strtolower((string) $key);

            if (str_contains($keyLower, 'pin') || str_contains($keyLower, 'secret')
                || str_contains($keyLower, 'signature') || str_contains($keyLower, 'authorization')
                || str_contains($keyLower, 'token')) {
                $this->line("  {$key}: <redacted — sensitive field name>");

                continue;
            }

            if (in_array($keyLower, $safeToShowValue, true) && ! is_array($value)) {
                $this->line("  {$key}: ".json_encode($value));

                continue;
            }

            if (is_array($value)) {
                $this->line("  {$key}: <object/array present — keys: ".implode(', ', array_keys($value)).'>');

                continue;
            }

            $this->line("  {$key}: <value present, type=".gettype($value).', redacted>');
        }
    }

    private function hostOnly(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        $len = strlen($digits);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($digits, -4);
    }

    private function sanitizeExceptionMessage(\Throwable $e): string
    {
        // Connection/timeout exception messages from Guzzle can occasionally
        // include the full request URI (query string only — never headers/body),
        // which is not sensitive here, but strip anything that looks like a
        // bearer/basic auth fragment just in case.
        $message = $e->getMessage();

        return (string) preg_replace('/(Authorization:\s*)\S+/i', '$1<redacted>', $message);
    }
}
