<?php

namespace Tests\Feature\Ai\Tools\Support;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolParameter;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;

/**
 * TEST-ONLY tool. Lives under tests/, is never registered by the application, performs no I/O, and records how it was
 * called so tests can assert exactly what the executor passed it.
 */
final class FakeTool implements AiTool
{
    public int $handled = 0;

    public int $authorized = 0;

    public ?ToolContext $lastContext = null;

    public ?ToolArguments $lastArguments = null;

    /** @var \Closure(ToolArguments, ToolContext):array|null */
    public ?\Closure $handler = null;

    /** @var \Closure(ToolContext):bool|null */
    public ?\Closure $authorizer = null;

    /** @param list<AiSurface> $surfaces */
    public function __construct(
        private readonly string $name = 'test_lookup',
        private readonly array $surfaces = [AiSurface::Vendor],
        private ?ToolSchema $schema = null,
        private readonly string $description = 'A test-only lookup tool.',
    ) {
        $this->schema ??= new ToolSchema(
            ToolParameter::string('query', 'Free text to look up.', required: true, maxLength: 50, minLength: 1),
            ToolParameter::integer('limit', 'How many rows to return.', 1, 5),
            ToolParameter::enum('sort', 'Sort order.', ['asc', 'desc']),
            ToolParameter::boolean('verbose', 'Include extra detail.'),
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function surfaces(): array
    {
        return $this->surfaces;
    }

    public function schema(): ToolSchema
    {
        return $this->schema;
    }

    public function authorize(ToolContext $context): bool
    {
        $this->authorized++;

        return $this->authorizer ? ($this->authorizer)($context) : true;
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $this->handled++;
        $this->lastContext = $context;
        $this->lastArguments = $arguments;

        return $this->handler
            ? ($this->handler)($arguments, $context)
            : ['vendor_id' => $context->vendorId, 'surface' => $context->surface->value, 'query' => $arguments->string('query'), 'args' => $arguments->all()];
    }
}
