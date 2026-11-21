<?php

namespace App\Services\Ai\CustomerAssistant;

/** What one assistant turn produced. Plain data; nothing here is ever stored. */
final class CustomerAssistantReply
{
    /**
     * @param  list<string>  $toolsUsed  distinct names of tools that ran and returned data
     * @param  list<array{label:string,path:string}>  $links  trusted links to the public service pages (built by the server from
     *                                                        tool results, never from model text)
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $toolsUsed,
        public readonly string $requestId,
        public readonly array $links = [],
    ) {}
}
