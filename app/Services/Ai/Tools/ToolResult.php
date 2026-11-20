<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRole;

/**
 * What the model is told about a tool call: either sanitised data, or a stable error code with a fixed, generic
 * sentence. Never an exception message, SQL, stack trace, class name or path.
 *
 * The model-facing JSON always labels the content `untrusted: true`. Tool output is DATA about the world (and may
 * include text a user typed); it can never grant permissions, change rules or issue instructions.
 */
final class ToolResult
{
    /** Fixed, generic messages. The only free text a model ever receives about an error. */
    private const MESSAGES = [
        'unknown_tool' => 'No such tool is available.',
        'invalid_call' => 'The tool call was malformed.',
        'duplicate_call' => 'This tool call id was already used in this turn.',
        'invalid_arguments' => 'The arguments were not valid for this tool.',
        'prohibited_argument' => 'Identity, ownership and permission fields cannot be supplied; they are determined by the server.',
        'arguments_too_large' => 'The arguments were too large.',
        'invalid_context' => 'The tool cannot run for this session.',
        'not_authorized' => 'This tool is not permitted here.',
        'tools_disabled' => 'Tools are currently disabled.',
        'too_many_tool_calls' => 'The limit of tool calls for this turn has been reached.',
        'turn_time_exceeded' => 'The time allowed for tools in this turn has been used.',
        'tool_error' => 'The tool could not complete the request.',
        'tool_timeout' => 'The tool took too long.',
        'read_only_violation' => 'The tool could not complete the request.',
        'invalid_result' => 'The tool returned an unusable result.',
        'result_too_large' => 'The tool result was too large to return.',
    ];

    /** @param list<array{parameter:string,problem:string}> $problems */
    private function __construct(
        public readonly ToolResultStatus $status,
        public readonly string $callId,
        public readonly string $toolName,
        public readonly array $data = [],
        public readonly ?string $errorCode = null,
        public readonly array $problems = [],
        public readonly bool $truncated = false,
        public readonly int $resultBytes = 0,
    ) {}

    /** @param array<string,mixed> $data already sanitised */
    public static function ok(string $callId, string $toolName, array $data, bool $truncated, int $resultBytes): self
    {
        return new self(ToolResultStatus::Ok, $callId, $toolName, $data, null, [], $truncated, $resultBytes);
    }

    /** @param list<array{parameter:string,problem:string}> $problems */
    public static function rejected(string $callId, string $toolName, string $code, array $problems = []): self
    {
        return new self(ToolResultStatus::Rejected, $callId, $toolName, [], $code, $problems);
    }

    public static function failed(string $callId, string $toolName, string $code): self
    {
        return new self(ToolResultStatus::Failed, $callId, $toolName, [], $code);
    }

    public function isOk(): bool
    {
        return $this->status === ToolResultStatus::Ok;
    }

    /** True when the tool's handle() was actually entered. */
    public function wasExecuted(): bool
    {
        return $this->status !== ToolResultStatus::Rejected;
    }

    public function safeMessage(): ?string
    {
        return $this->errorCode === null ? null : (self::MESSAGES[$this->errorCode] ?? 'The tool call could not be completed.');
    }

    /** The JSON the model receives for this call. */
    public function toModelPayload(): string
    {
        $payload = [
            'tool_call_id' => $this->callId,
            'tool' => $this->toolName,
            'status' => $this->status->value,
            'untrusted' => true,
        ];

        if ($this->isOk()) {
            $payload['data'] = $this->data === [] ? (object) [] : $this->data;
            $payload['truncated'] = $this->truncated;
        } else {
            $payload['error'] = ['code' => $this->errorCode, 'message' => $this->safeMessage()];
            if ($this->problems !== []) {
                $payload['error']['problems'] = $this->problems;
            }
        }

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** As a conversation message (role `tool`), ready for a future provider round-trip. */
    public function toAiMessage(): AiMessage
    {
        return new AiMessage(AiRole::Tool, $this->toModelPayload());
    }
}
