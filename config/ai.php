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
    // Global kill switch (config half). The runtime half is `php artisan ai:kill-switch on|off`, which wins.
    'enabled' => (bool) env('AI_ENABLED', false),

    // 'mock' (offline, default) or 'openai'. The OpenAI adapter is NOT implemented in M5.2: selecting it
    // fails closed with a configuration error and makes no network request.
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
            // Read lazily by the future adapter only. Never logged, never echoed, never part of an exception message.
            'api_key' => env('AI_OPENAI_API_KEY'),
            'base_url' => env('AI_OPENAI_BASE_URL'),
        ],
    ],

    // Per-surface switches. None of these surfaces has a UI/route yet; this lets each one be released independently.
    'surfaces' => [
        'customer' => ['enabled' => (bool) env('AI_SURFACE_CUSTOMER', false)],
        'vendor' => ['enabled' => (bool) env('AI_SURFACE_VENDOR', false)],
        'developer' => ['enabled' => (bool) env('AI_SURFACE_DEVELOPER', false)],
    ],

    'request' => [
        // Per provider attempt. Hard-clamped to max_timeout_seconds whatever a caller asks for.
        'timeout_seconds' => 20,
        'max_timeout_seconds' => 30,
        // Whole turn (all attempts). Retries never start if the remaining time cannot fit another attempt.
        'deadline_seconds' => 40,
        // Retries on timeout / provider-unavailable only; hard-capped at 2 in code. 0 disables retries.
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

    // Estimated cost needs reliable pricing; none is shipped. Shape (USD per 1,000,000 tokens):
    //   'pricing' => ['openai' => ['<model>' => ['input_usd_per_million' => X, 'output_usd_per_million' => Y, 'as_of' => 'YYYY-MM-DD']]]
    // Without an entry the cost is "unknown" (null) and is neither recorded nor enforced.
    'pricing' => [],

    'circuit_breaker' => [
        'failure_threshold' => 5,
        'window_seconds' => 120,
        'cooldown_seconds' => 60,
    ],

    /*
    | AI tool system (M5.3). See docs/ai/02-tool-system.md.
    |
    | `allowlist` is the ONLY thing that makes a registered tool callable on a surface (and the tool itself must also
    | declare that surface). `customer` ships EMPTY. M5.4 lists the Developer Assistant's four read-only tools on
    | `developer` and M5.5 the Vendor Assistant's six on `vendor`; all are inert while the engine and the respective surface stay disabled (the defaults above).
    | These are code-level settings on purpose: they are not read from .env, the database, or any request.
    */
    'tools' => [
        'allowlist' => [
            'customer' => [],
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
