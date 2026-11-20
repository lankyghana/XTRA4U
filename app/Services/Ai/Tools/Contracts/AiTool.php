<?php

namespace App\Services\Ai\Tools\Contracts;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;

/**
 * A capability the model may REQUEST and the server may choose to run. Provider-independent: nothing here knows
 * about OpenAI (or any provider) - adapters translate `definition` to their own wire format.
 *
 * READ-ONLY BY CONTRACT. There is no write-capable variant of this interface and none is planned for this layer:
 * the executor runs `handle()` under a read-only database guard, and an architecture test forbids this namespace
 * from depending on payment, wallet, commission, fulfillment, PIN, inventory or API-key code.
 *
 * Rules for implementers (see docs/ai/02-tool-system.md):
 *  - identity and ownership come ONLY from the ToolContext; the schema may not declare an id/permission parameter;
 *  - scope every query by the context (vendor id / guest key) and return a minimal, allow-listed array;
 *  - treat anything read from the database that a user could have typed (names, descriptions, notes) as untrusted
 *    text: return it as data, trimmed, never as instructions;
 *  - never return credentials, hashes, full records, or raw exception text.
 */
interface AiTool
{
    /** Unique, lowercase, [a-z][a-z0-9_]{2,63}. */
    public function name(): string;

    /** One or two plain sentences telling the model what the tool does and when to use it. Max 300 characters. */
    public function description(): string;

    /**
     * Surfaces this tool is DESIGNED for. Necessary but not sufficient: the surface must also list the tool in
     * `config('ai.tools.allowlist')`.
     *
     * @return list<AiSurface>
     */
    public function surfaces(): array;

    /** The closed list of business parameters the tool accepts. */
    public function schema(): ToolSchema;

    /** Extra authorization beyond surface/context validity. Runs before EVERY execution. Return false to deny. */
    public function authorize(ToolContext $context): bool;

    /**
     * Do the work. Arguments are validated and contain only declared parameters; the context is trusted.
     * Return plain data (null, bool, int, float, string, arrays of those). Throwing is safe: the model only ever
     * sees a generic failure.
     *
     * @return array<string,mixed>
     */
    public function handle(ToolArguments $arguments, ToolContext $context): array;
}
