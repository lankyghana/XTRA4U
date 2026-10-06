<?php

namespace Tests\Feature;

use Tests\TestCase;

class SkdataplugWebhookSignatureTest extends TestCase
{
    public function test_unsigned_request_is_rejected_in_production_when_no_token_is_configured(): void
    {
        $this->app['env'] = 'production';
        config()->set('services.skdataplug.token', '');

        $this->postJson('/webhooks/skdataplug', ['reference' => 'X'])->assertStatus(401);
    }

    public function test_bad_signature_is_rejected_when_token_is_configured(): void
    {
        config()->set('services.skdataplug.token', 'secret');

        $this->postJson('/webhooks/skdataplug', ['reference' => 'X'], ['X-SKPlug-Signature' => 'nope'])
            ->assertStatus(401);
    }
}
