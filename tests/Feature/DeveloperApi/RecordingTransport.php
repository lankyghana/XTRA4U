<?php

namespace Tests\Feature\DeveloperApi;

use App\Services\DeveloperApi\Webhooks\TransportResult;
use App\Services\DeveloperApi\Webhooks\ValidatedDestination;
use App\Services\DeveloperApi\Webhooks\WebhookTransport;

/** A webhook transport that never touches the network: records every send and answers with a canned result. */
final class RecordingTransport implements WebhookTransport
{
    /** @var list<array{host:string,headers:array<string,string>,body:string}> */
    public array $sent = [];

    public function __construct(public string $outcome = 'success', public ?int $status = 200) {}

    public function send(ValidatedDestination $destination, array $headers, string $body): TransportResult
    {
        $this->sent[] = ['host' => (string) ($destination->host ?? ''), 'headers' => $headers, 'body' => $body];

        return new TransportResult($this->outcome, $this->status, 5, $this->outcome === 'success' ? null : 'http_error');
    }

    /** @return list<array<string,mixed>> decoded JSON bodies */
    public function bodies(): array
    {
        return array_map(fn ($s) => json_decode($s['body'], true), $this->sent);
    }
}
