<?php

/*
| XTRA4U AI engine foundation (M5.2). See docs/ai/01-foundation.md.
|
| SAFE BY DEFAULT: the engine is OFF, every surface is OFF, the provider is the offline mock, and no limit is
| unlimited by accident. Nothing here is read from .env except the values named below, and nothing in this
| file is a secret. A blank/absent env value yields null ("not configured"), never 0 or "unlimited".
*/

$int = static function (string $key, ?int $default = null): ?int {
    $v = env($key);

    return ($v === null || $v === '') ? $default : (int) $v;
};

$limits = static fn (string $prefix): array => [
    // null = NOT CONFIGURED. For a live (network) provider the token limits below are REQUIRED: the engine
    // refuses to run rather than treating "not configured" as unlimited. 0 = hard block.
    'daily_requests' => $int("AI_{$prefix}_DAILY_REQUESTS"),
    'daily_tokens' => $int("AI_{$prefix}_DAILY_TOKENS"),
    // Optional. Enforced only when pricing for the model is configured below (never guessed).
    'daily_cost_micro_usd' => $int("AI_{$prefix}_DAILY_COST_MICRO_USD"),
];

return [
    // Global kill switch (config half). The runtime half is `php artisan ai:kill-switch engage|release|status`, which wins.
    'enabled' => (bool) env('AI_ENABLED', false),

    // 'mock' (offline, default) or 'openai'. Selecting 'openai' (M5.7 adapter) fails closed unless EVERY gate in
    // docs/ai/06-production-hardening-and-launch-readiness.md is satisfied (external-processing approval, key, model,
    // pricing, budgets, cache engine). The default must stay 'mock'.
    'provider' => env('AI_PROVIDER', 'mock'),

    // Placeholder. Deliberately no real model name is shipped; a live provider refuses to run while this is null
    // (the mock provider supplies its own offline model name).
    'default_model' => env('AI_DEFAULT_MODEL'),

    'providers' => [
        'mock' => [
            // The mock must never silently answer real users in production.
            'allowed_in_production' => (bool) env('AI_MOCK_IN_PRODUCTION', false),
            'model' => 'mock-model',
        ],
        'openai' => [
            // Read lazily by the adapter only. Never logged, never echoed, never part of an exception message.
            // There is deliberately NO base-url setting: the endpoint is a constant in OpenAiProvider (HTTPS, fixed
            // host), so no config value, request field, model output or tool argument can redirect a request.
            'api_key' => env('AI_OPENAI_API_KEY'),
        ],
    ],

    /*
    | External-processing approval gate (M5.7). Sending prompts to a third party is a privacy decision, not a
    | technical one. This gate ships CLOSED and a live provider refuses to run until it is explicitly opened WITH a
    | traceable approval record. An environment variable is not proof that approval happened: it is a record that a
    | named, authorised person says it did. See docs/ai/06-production-hardening-and-launch-readiness.md section 5.
    */
    'external_processing' => [
        'approved' => (bool) env('AI_EXTERNAL_PROCESSING_APPROVED', false),
        'approval_reference' => env('AI_EXTERNAL_PROCESSING_APPROVAL_REF'),
        'approved_by' => env('AI_EXTERNAL_PROCESSING_APPROVED_BY'),
        'approved_on' => env('AI_EXTERNAL_PROCESSING_APPROVED_ON'),
    ],

    /*
    | Operator attestations (M5.7). Facts about the hosting environment that the application CANNOT determine itself and
    | that `ai:preflight --live` therefore refuses to guess. Both ship false. Setting one to true is a statement by the
    | person responsible for the deployment that it is true (docs/ai/06); it does not make it so.
    */
    'operations' => [
        // The app is reached DIRECTLY (no reverse proxy / CDN / load balancer in front), so the connecting address is the
        // visitor. The alternative is to list the proxies in TRUSTED_PROXIES.
        'direct_connections_confirmed' => (bool) env('AI_DIRECT_CONNECTIONS_CONFIRMED', false),
        // The PHP worker pool is large enough that `ai.concurrency.max_in_flight` workers can be held for up to
        // `ai.request.turn_deadline_seconds` each without starving checkout, and the web-server / FPM request timeout is
        // longer than that deadline. Required before the PUBLIC customer surface may go live.
        'customer_worker_capacity_confirmed' => (bool) env('AI_CUSTOMER_WORKER_CAPACITY_CONFIRMED', false),
        // M5.7.2. A spending cap is set at the PROVIDER (account/project limit). Local budgets do not replace it: they are computed
        // from configured prices, the provider bills what it counts. No amount is stored here and none is suggested; the application
        // never calls the provider to check. Setting this to true is a statement by the person who configured the cap, and the
        // reference says where that is recorded (ticket, console screenshot path, change record).
        'provider_spending_cap_confirmed' => (bool) env('AI_PROVIDER_SPENDING_CAP_CONFIRMED', false),
        'provider_spending_cap_reference' => env('AI_PROVIDER_SPENDING_CAP_REFERENCE'),
    ],

    /*
    | Independent integrity anchor (M5.7.2). See docs/ai/06 section 19.
    |
    | The budget ledger, the counters and the kill switch live in the application database, so a restore of an older database
    | takes all of them back together and nothing inside the database can tell. An ANCHOR is a second, authoritative record
    | kept somewhere that is NOT restored with the database. Driver `none` (the default) means there is none: a live provider
    | then refuses to run. Driver `file` stores an authenticated document in a directory the operator provides on storage that
    | has its own backup and restore lifecycle. The repository cannot verify that independence, so the operator must attest to
    | it and say where it is documented; `ai:preflight` fails until they do.
    */
    'integrity' => [
        'anchor' => [
            'driver' => env('AI_INTEGRITY_ANCHOR_DRIVER', 'none'),
            'path' => env('AI_INTEGRITY_ANCHOR_PATH'),
            // A secret of at least 32 characters, kept apart from the database (it authenticates the anchor document).
            'key' => env('AI_INTEGRITY_ANCHOR_KEY'),
            'independence_attested' => (bool) env('AI_INTEGRITY_ANCHOR_INDEPENDENCE_ATTESTED', false),
            'independence_reference' => env('AI_INTEGRITY_ANCHOR_INDEPENDENCE_REF'),
        ],
    ],

    // Per-surface switches. None of these surfaces has a UI/route yet; this lets each one be released independently.
    'surfaces' => [
        'customer' => ['enabled' => (bool) env('AI_SURFACE_CUSTOMER', false)],
        'vendor' => ['enabled' => (bool) env('AI_SURFACE_VENDOR', false)],
        'developer' => ['enabled' => (bool) env('AI_SURFACE_DEVELOPER', false)],
    ],

    'request' => [
        // Per provider attempt (total transfer time, cURL CURLOPT_TIMEOUT). Hard-clamped to max_timeout_seconds.
        'timeout_seconds' => 15,
        'max_timeout_seconds' => 20,
        // TCP/TLS connect phase only (the request has NOT been sent if this expires).
        'connect_timeout_seconds' => 5,
        // One orchestrator run (all attempts of ONE provider round). Retries never start if they cannot fit.
        // Invariant (validated): timeout <= max_timeout <= deadline_seconds <= turn_deadline_seconds.
        'deadline_seconds' => 20,
        // The WHOLE assistant turn: round 1 + tools + round 2. Assistants stamp this deadline on both requests, so
        // the second round only gets what is left and is refused when too little remains. Must be below the
        // web-server / PHP-FPM request timeout (document + verify per host).
        'turn_deadline_seconds' => 40,
        // Retries on timeout / provider-unavailable only; hard-capped at 2 in code. 0 disables retries.
        // A LIVE provider is only ever retried when the request provably never left this server (no idempotency key
        // exists on the provider, so an ambiguous attempt is never repeated).
        'max_retries' => 1,
        // Serialized size of messages + tool schemas, in bytes.
        'max_request_bytes' => 16000,
        // Conversation context window the engine will accept (messages, newest included).
        'max_context_messages' => 20,
        // Completion allowance; callers can ask for less, never more.
        'max_output_tokens' => 512,
    ],

    // Requests per minute PER PRINCIPAL (guest session / vendor), per surface. Missing/invalid = misconfiguration.
    'rate_limits' => [
        'customer' => ['per_minute' => 6],
        'vendor' => ['per_minute' => 20],
        'developer' => ['per_minute' => 20],
    ],

    // Shared (cache-lock) cap on provider calls in flight across ALL PHP processes. Each synchronous AI call
    // holds a PHP worker, so this protects checkout. Slots auto-expire (lock_seconds) if a process dies.
    'concurrency' => [
        'max_in_flight' => 2,
        'lock_seconds' => 45,
    ],

    // Daily (app timezone) budgets: global and per surface.
    'limits' => [
        'global' => $limits('GLOBAL'),
        'surfaces' => [
            'customer' => $limits('CUSTOMER'),
            'vendor' => $limits('VENDOR'),
            'developer' => $limits('DEVELOPER'),
        ],
    ],

    // Token-accounting bound (M5.7): see docs/ai/06 section 6. input upper bound =
    //   bytes(system+messages) + 2 x bytes(tool definitions) + per_message_overhead x messages + fixed_overhead.
    'budget' => [
        'fixed_overhead_tokens' => 512,
        'per_message_overhead_tokens' => 16,
        'tool_schema_multiplier' => 2,
    ],

    // Cost needs reliable pricing; NO price is shipped or guessed. Shape (USD per 1,000,000 tokens):
    //   'pricing' => ['openai' => ['<model>' => ['input_usd_per_million' => X, 'output_usd_per_million' => Y, 'as_of' => 'YYYY-MM-DD']]]
    // An entry is created ONLY when the operator supplies the model AND both rates through the environment, copied
    // from the provider's official price list on the date recorded in AI_OPENAI_PRICING_AS_OF. A live provider will not
    // run without a complete entry for its model (it must reserve a maximum cost before every call).
    'pricing' => (static function (): array {
        $model = env('AI_DEFAULT_MODEL');
        $in = env('AI_OPENAI_INPUT_USD_PER_MILLION');
        $out = env('AI_OPENAI_OUTPUT_USD_PER_MILLION');

        if (! is_string($model) || $model === '' || ! is_numeric($in) || ! is_numeric($out)) {
            return [];
        }

        return ['openai' => [$model => [
            'input_usd_per_million' => (float) $in,
            'output_usd_per_million' => (float) $out,
            'as_of' => (string) env('AI_OPENAI_PRICING_AS_OF', ''),
        ]]];
    })(),

    'circuit_breaker' => [
        'failure_threshold' => 5,
        'window_seconds' => 120,
        'cooldown_seconds' => 60,
    ],

    /*
    | AI tool system (M5.3). See docs/ai/02-tool-system.md.
    |
    | `allowlist` is the ONLY thing that makes a registered tool callable on a surface (and the tool itself must also
    | declare that surface). `customer` was EMPTY until M5.6. M5.4 lists the Developer Assistant's four read-only tools on
    | `developer`, M5.5 the Vendor Assistant's six on `vendor` and M5.6 the Customer Assistant's four public-catalogue tools
    | on `customer`; all are inert while the engine and the respective surface stay disabled (the defaults above).
    | These are code-level settings on purpose: they are not read from .env, the database, or any request.
    */
    'tools' => [
        'allowlist' => [
            'customer' => [
                'customer_list_services',
                'customer_search_products',
                'customer_get_product',
                'customer_search_help',
            ],
            'vendor' => [
                'vendor_get_dashboard_summary',
                'vendor_search_my_orders',
                'vendor_get_my_order',
                'vendor_list_my_products',
                'vendor_get_earnings_summary',
                'vendor_get_my_store_info',
            ],
            'developer' => [
                'developer_search_docs',
                'developer_read_doc',
                'developer_list_my_apps',
                'developer_get_my_app',
            ],
        ],

        'limits' => [
            'max_calls_per_turn' => 4,      // tool calls honoured in one assistant turn (rejected ones count too)
            'max_arguments' => 8,           // keys in one call's arguments
            'max_argument_bytes' => 2048,   // JSON size of one call's arguments
            'max_string_length' => 500,     // hard ceiling for any single string argument
            'max_result_bytes' => 8192,     // JSON size of one result (over = refused, never silently cut)
            'max_result_items' => 50,       // elements in any one list/map of a result
            'max_result_depth' => 5,        // nesting depth of a result
            'max_result_string_length' => 1000, // longer strings are truncated and the result is flagged
            'max_execution_seconds' => 5,   // per call; enforced after the fact (PHP cannot interrupt a running tool)
            'max_turn_seconds' => 15,       // total tool time per turn; later calls are refused once spent
        ],

        // Argument names that may never be declared by a tool nor sent by a model, compared after stripping
        // everything but letters/digits and lower-casing ("vendor-id", "vendorId" and "VENDOR_ID" are the same).
        // Identity, ownership and authorization come from the trusted ToolContext only.
        'prohibited_argument_names' => [
            'userid', 'vendorid', 'customerid', 'actorid', 'accountid', 'tenantid', 'ownerid', 'applicationid',
            'appid', 'sessionid', 'guestid', 'adminid', 'resellerid',
            'user', 'vendor', 'customer', 'actor', 'account', 'tenant', 'owner', 'role', 'roles',
            'isadmin', 'isapproved', 'approved', 'authenticated', 'permission', 'permissions', 'scope', 'scopes',
            'capability', 'capabilities', 'surface', 'actortype', 'context', 'toolcontext', 'guard', 'impersonate',
        ],
    ],

    'logging' => [
        // null = the application's default log channel. Only redacted metadata is ever logged (never prompts/responses).
        'channel' => env('AI_LOG_CHANNEL'),
    ],
];
