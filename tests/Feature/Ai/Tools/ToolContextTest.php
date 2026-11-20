<?php

namespace Tests\Feature\Ai\Tools;

use App\Models\Vendor;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Tools\Exceptions\AiToolContextException;
use App\Services\Ai\Tools\ToolActorType;
use App\Services\Ai\Tools\ToolContext;
use Tests\Feature\Ai\Tools\Support\FakeTool;

class ToolContextTest extends ToolTestCase
{
    public function test_the_trusted_context_reaches_the_tool_unchanged(): void
    {
        $vendor = $this->approvedVendor();
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);
        $context = ToolContext::forVendor($vendor);

        $result = $this->callTool('test_lookup', ['query' => 'mtn'], $context);

        $this->assertTrue($result->isOk(), json_encode($result));
        $this->assertSame($context, $tool->lastContext);
        $this->assertSame($vendor->id, $result->data['vendor_id']);
        $this->assertSame('vendor', $result->data['surface']);
    }

    public function test_the_context_is_built_from_server_side_actors_only(): void
    {
        $vendor = $this->approvedVendor();

        $v = ToolContext::forVendor($vendor);
        $this->assertSame([AiSurface::Vendor, ToolActorType::Vendor, $vendor->id, $vendor->id, null], [$v->surface, $v->actorType, $v->actorId, $v->vendorId, $v->guestKey]);
        $this->assertSame("vendor:{$vendor->id}", $v->principalKey());
        $this->assertTrue($v->can(ToolContext::CAP_VENDOR_APPROVED));
        $this->assertFalse($v->can(ToolContext::CAP_DEVELOPER_PORTAL));

        $d = ToolContext::forDeveloper($vendor);
        $this->assertSame(AiSurface::Developer, $d->surface);
        $this->assertSame($vendor->id, $d->vendorId);
        $this->assertTrue($d->can(ToolContext::CAP_DEVELOPER_PORTAL));

        $g = ToolContext::forGuest('a-server-side-session-id-0123456789');
        $this->assertSame([AiSurface::Customer, ToolActorType::Guest, null, null], [$g->surface, $g->actorType, $g->actorId, $g->vendorId]);
        $this->assertSame(hash('sha256', 'a-server-side-session-id-0123456789'), $g->guestKey, 'only a one-way hash of the session id is kept');
        $this->assertStringNotContainsString('a-server-side', $g->principalKey());
    }

    public function test_a_context_cannot_be_forged_publicly(): void
    {
        $this->assertTrue((new \ReflectionClass(ToolContext::class))->getConstructor()->isPrivate());
        $this->assertTrue((new \ReflectionClass(ToolContext::class))->isFinal());

        $vendor = $this->approvedVendor();
        $this->expectException(\Error::class);
        $c = ToolContext::forVendor($vendor);
        $c->vendorId = 999; // immutable
    }

    public function test_a_client_supplied_context_cannot_be_deserialised(): void
    {
        $payload = 'O:'.strlen(ToolContext::class).':"'.ToolContext::class.'":6:{s:7:"surface";s:6:"vendor";s:9:"actorType";s:6:"vendor";s:7:"actorId";i:1;s:8:"vendorId";i:1;s:8:"guestKey";N;s:12:"capabilities";a:1:{i:0;s:15:"vendor.approved";}}';

        $this->expectException(AiToolContextException::class);
        unserialize($payload);
    }

    public function test_building_a_context_for_an_unapproved_or_unsaved_vendor_fails(): void
    {
        foreach ([
            Vendor::factory()->create(['is_approved' => false])->fresh(),
            Vendor::factory()->make(['is_approved' => true]),   // not persisted
            new Vendor(['is_approved' => true]),                // forged in memory
        ] as $vendor) {
            try {
                ToolContext::forVendor($vendor);
                $this->fail('Expected a context error.');
            } catch (AiToolContextException $e) {
                $this->assertSame('ai_tool_context_invalid', $e->errorCode());
            }
        }

        $this->expectException(AiToolContextException::class);
        ToolContext::forGuest('short');
    }

    public function test_model_arguments_cannot_override_vendor_identity(): void
    {
        $mine = $this->approvedVendor();
        $other = $this->approvedVendor();
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);

        foreach (['vendor_id', 'vendorId', 'target_vendor_id', 'user_id', 'actor_id', 'owner', 'account_id', 'tenant_id'] as $key) {
            $result = $this->callTool('test_lookup', ['query' => 'x', $key => $other->id], ToolContext::forVendor($mine));

            $this->assertSame('rejected', $result->status->value, $key);
            $this->assertSame('prohibited_argument', $result->errorCode, $key);
        }

        $this->assertSame(0, $tool->handled, 'a rejected call never reaches the tool');

        // The same call without the override runs as the AUTHENTICATED vendor, never the one the model named.
        $ok = $this->callTool('test_lookup', ['query' => 'x'], ToolContext::forVendor($mine));
        $this->assertSame($mine->id, $ok->data['vendor_id']);
        $this->assertNotSame($other->id, $tool->lastContext->vendorId);
    }

    public function test_model_arguments_cannot_override_customer_identity(): void
    {
        $tool = $this->enable(new FakeTool('customer_lookup', [AiSurface::Customer]), AiSurface::Customer);
        $guest = ToolContext::forGuest('server-session-aaaaaaaaaaaaaaaa');

        foreach (['customer_id', 'customerId', 'session_id', 'guest_id', 'user_id', 'phone_owner_id'] as $key) {
            $result = $this->callTool('customer_lookup', ['query' => 'x', $key => 7], $guest);
            $this->assertSame('prohibited_argument', $result->errorCode, $key);
        }

        $this->assertSame(0, $tool->handled);
    }

    public function test_model_arguments_cannot_carry_permissions_approval_or_a_context(): void
    {
        $this->enable(new FakeTool, AiSurface::Vendor);
        $ctx = $this->vendorContext();

        foreach (['is_admin' => true, 'is_approved' => true, 'role' => 'admin', 'permissions' => ['x'], 'scopes' => 'all', 'context' => 'vendor', 'tool_context' => 'x', 'surface' => 'customer', 'authenticated' => true, 'impersonate' => 5] as $key => $value) {
            $result = $this->callTool('test_lookup', ['query' => 'x', $key => $value], $ctx);
            $this->assertSame('prohibited_argument', $result->errorCode, $key);
        }
    }

    public function test_the_executor_only_accepts_a_real_context_object(): void
    {
        $this->enable(new FakeTool, AiSurface::Vendor);
        $executor = $this->executor();

        foreach ([['surface' => 'vendor', 'vendorId' => 1], (object) ['vendorId' => 1], 'vendor:1', null] as $forged) {
            try {
                $executor->execute($this->toolCall('test_lookup', ['query' => 'x']), $forged, $executor->beginTurn());
                $this->fail('Expected a TypeError.');
            } catch (\TypeError) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_context_that_no_longer_verifies_prevents_execution(): void
    {
        $vendor = $this->approvedVendor();
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);
        $context = ToolContext::forVendor($vendor);

        $this->assertTrue($this->callTool('test_lookup', ['query' => 'x'], $context)->isOk());

        // suspended between two calls of the same turn
        $vendor->forceFill(['is_approved' => false])->save();
        $result = $this->callTool('test_lookup', ['query' => 'x'], $context, 'call_2');

        $this->assertSame('invalid_context', $result->errorCode);
        $this->assertSame(1, $tool->handled);

        // a vendor that does not exist at all
        $vendor->delete();
        $this->assertSame('invalid_context', $this->callTool('test_lookup', ['query' => 'x'], $context, 'call_3')->errorCode);
    }

    public function test_a_context_for_the_wrong_surface_cannot_reach_a_tool(): void
    {
        $tool = $this->enable(new FakeTool('vendor_tool', [AiSurface::Vendor]), AiSurface::Vendor);

        $result = $this->callTool('vendor_tool', ['query' => 'x'], ToolContext::forGuest('server-session-aaaaaaaaaaaaaaaa'));
        $this->assertSame('unknown_tool', $result->errorCode);

        $result = $this->callTool('vendor_tool', ['query' => 'x'], ToolContext::forDeveloper($this->approvedVendor()));
        $this->assertSame('unknown_tool', $result->errorCode);
        $this->assertSame(0, $tool->handled);
    }
}
