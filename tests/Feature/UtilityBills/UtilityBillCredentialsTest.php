<?php

namespace Tests\Feature\UtilityBills;

use App\Models\Setting;
use App\Models\User;
use App\Models\UtilityBillConfigAudit;
use App\Services\UtilityBills\KingFlexyUtilityProvider;
use App\Services\UtilityBills\UtilityBillCredentials;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class UtilityBillCredentialsTest extends UtilityBillTestCase
{
    private const KEY = 'kf_cs_live_adminsavedkey9876';

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_admin_can_save_key_encrypted_and_it_is_never_rendered_or_audited(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $this->admin();

        $this->put(route('admin.utility-bills.settings.credentials'), ['api_key' => self::KEY])
            ->assertRedirect(route('admin.utility-bills.settings'));

        $raw = (string) Setting::where('key', UtilityBillCredentials::KEY)->value('value');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('adminsavedkey', $raw);
        $this->assertSame(self::KEY, UtilityBillCredentials::apiKey());

        $page = $this->get(route('admin.utility-bills.settings'))->assertOk();
        $page->assertSee('9876');
        $page->assertDontSee(self::KEY, false);

        $audit = UtilityBillConfigAudit::where('scope', 'credentials')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('adminsavedkey', json_encode([$audit->old_values, $audit->new_values]));
        $this->assertSame('admin', $audit->new_values['source']);
    }

    public function test_provider_uses_saved_key_over_env_and_falls_back_after_removal(): void
    {
        config(['services.kingflexy_utilities.api_key' => 'kf_cs_live_envkey1111']);
        $this->admin();
        $this->put(route('admin.utility-bills.settings.credentials'), ['api_key' => self::KEY]);

        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        app(KingFlexyUtilityProvider::class)->catalog();
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', self::KEY));

        $this->put(route('admin.utility-bills.settings.credentials'), ['action' => 'clear'])->assertRedirect();
        $this->assertSame('kf_cs_live_envkey1111', UtilityBillCredentials::apiKey());
        $this->assertSame('env', UtilityBillCredentials::describe()['source']);
    }

    public function test_non_commission_key_is_rejected_and_nothing_saved(): void
    {
        $this->admin();

        $this->put(route('admin.utility-bills.settings.credentials'), ['api_key' => 'kf_live_normaldatakey'])
            ->assertSessionHasErrors('api_key');

        $this->assertSame('', (string) Setting::where('key', UtilityBillCredentials::KEY)->value('value'));
    }

    public function test_non_admin_cannot_change_the_key(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));

        $this->put(route('admin.utility-bills.settings.credentials'), ['api_key' => self::KEY])->assertStatus(403);

        $this->assertSame('', (string) Setting::where('key', UtilityBillCredentials::KEY)->value('value'));
    }
}
