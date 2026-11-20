<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Exceptions\AiToolRegistrationException;

/**
 * The one place tools are known. Holds tool OBJECTS handed over by trusted code; it never instantiates a class from
 * a string, a request, the model or the database, and once sealed (at the end of AiServiceProvider::boot) nothing
 * can be added for the rest of the process.
 *
 * Availability on a surface needs BOTH:
 *   1. the tool declares the surface in `surfaces()`, and
 *   2. the tool's name is in `config('ai.tools.allowlist.<surface>')` (ships EMPTY).
 * Anything else is "unavailable" - identical to "unknown" from the model's point of view.
 */
class ToolRegistry
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{2,63}$/D';

    /** @var array<string,AiTool> */
    private array $tools = [];

    private bool $sealed = false;

    /** @throws AiToolRegistrationException */
    public function register(AiTool $tool): void
    {
        if ($this->sealed) {
            throw new AiToolRegistrationException('The tool registry is sealed; tools can only be registered during application boot.');
        }

        $name = $tool->name();

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new AiToolRegistrationException('A tool name must match '.self::NAME_PATTERN.'.');
        }

        if (isset($this->tools[$name])) {
            throw new AiToolRegistrationException("Tool [{$name}] is already registered.");
        }

        $description = trim($tool->description());
        if ($description === '' || mb_strlen($description) > 300) {
            throw new AiToolRegistrationException("Tool [{$name}] needs a description of 1-300 characters.");
        }

        $surfaces = $tool->surfaces();
        if ($surfaces === [] || array_filter($surfaces, fn ($s) => ! $s instanceof AiSurface) !== []) {
            throw new AiToolRegistrationException("Tool [{$name}] must declare at least one valid surface.");
        }

        $limits = ToolLimits::fromConfig();
        $schema = $tool->schema();
        foreach ($schema->parameterNames() as $parameter) {
            if ($limits->isProhibitedName($parameter)) {
                throw new AiToolRegistrationException("Tool [{$name}] declares the prohibited parameter [{$parameter}]: identity and permissions come from the tool context only.");
            }
        }

        if (strlen((string) json_encode($schema->toJsonSchema())) > 4096) {
            throw new AiToolRegistrationException("Tool [{$name}] has an over-complex schema.");
        }

        $this->tools[$name] = $tool;
    }

    /** No further registration is possible for the lifetime of this registry. */
    public function seal(): void
    {
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /** @throws AiToolRegistrationException when no such tool is registered (surface-blind: administrative lookup) */
    public function get(string $name): AiTool
    {
        return $this->tools[$name] ?? throw new AiToolRegistrationException('Unknown tool.');
    }

    /** The tool, but only if it is available on this surface right now; otherwise null. Used on every execution. */
    public function resolve(string $name, AiSurface $surface): ?AiTool
    {
        $tool = $this->tools[$name] ?? null;

        if ($tool === null || ! $this->allowedOn($tool, $surface)) {
            return null;
        }

        return $tool;
    }

    /**
     * Only the tools available on this surface.
     *
     * @return list<AiTool>
     */
    public function forSurface(AiSurface $surface): array
    {
        return array_values(array_filter($this->tools, fn (AiTool $t) => $this->allowedOn($t, $surface)));
    }

    /**
     * Provider-neutral definitions for ONE surface, shaped for `AiRequest::$tools`:
     * [{name, description, parameters: <JSON schema>}]. Listing a tool here does not make it executable: the
     * executor re-checks availability on every call.
     *
     * @return list<array{name:string,description:string,parameters:array}>
     */
    public function definitionsFor(AiSurface $surface): array
    {
        return array_map(fn (AiTool $t) => [
            'name' => $t->name(),
            'description' => trim($t->description()),
            'parameters' => $t->schema()->toJsonSchema(),
        ], $this->forSurface($surface));
    }

    private function allowedOn(AiTool $tool, AiSurface $surface): bool
    {
        if (! in_array($surface, $tool->surfaces(), true)) {
            return false;
        }

        $allowlist = config("ai.tools.allowlist.{$surface->value}");

        return is_array($allowlist) && in_array($tool->name(), $allowlist, true);
    }
}
