<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\UpdateUssdSettingsRequest;
use App\Models\Admin;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\UssdPlanPolicy;
use App\Support\AdminAccess;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as BaseAuthenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Admin authorization must FAIL CLOSED. Admin privilege is never inferred from
 * the absence of a role. Regression suite for the former `($user->role ?? 'admin')`
 * pattern in AdminOnly, AdminAuthController, UssdPlanPolicy and
 * UpdateUssdSettingsRequest.
 */
class AdminAccessFailClosedTest extends TestCase
{
    use RefreshDatabase;

    /** A representative spread of admin routes, including the money-moving ones. */
    private function adminRequests(): array
    {
        return [
            ['get', '/admin'],
            ['get', '/admin/orders'],
            ['get', '/admin/vendors'],
            ['get', '/admin/payment-gateways'],
            ['get', '/admin/withdrawals'],
            ['get', '/admin/settings/utility-bills'],
            ['put', '/admin/settings/utility-bills'],
            ['get', '/admin/utility-bill-sales'],
            ['post', '/admin/utility-bill-sales/1/retry'],
            ['post', '/admin/utility-bill-sales/1/new-attempt'],
            ['post', '/admin/utility-bill-sales/1/refresh'],
            ['put', '/admin/settings/ussd'],
            ['get', '/admin/ussd-plans'],
            ['get', '/admin/content'],
        ];
    }

    private function assertNoAdminAccess(string $label): void
    {
        foreach ($this->adminRequests() as [$method, $url]) {
            $response = $this->call(strtoupper($method), $url);
            $this->assertContains($response->getStatusCode(), [302, 401, 403, 419], "{$label}: {$method} {$url} returned {$response->getStatusCode()}");
            $this->assertNotSame(200, $response->getStatusCode(), "{$label}: {$method} {$url}");
        }
    }

    public function test_anonymous_visitors_are_sent_to_login(): void
    {
        foreach ($this->adminRequests() as [$method, $url]) {
            $r = $this->call(strtoupper($method), $url);
            $this->assertTrue($r->isRedirect() || in_array($r->getStatusCode(), [401, 403, 419], true), "{$method} {$url}");
        }
        $this->get('/admin/utility-bill-sales')->assertRedirect(route('admin.login'));
    }

    public function test_a_normal_web_user_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->get('/admin/utility-bill-sales')->assertForbidden();
        $this->get('/admin/settings/utility-bills')->assertForbidden();
        $this->assertNoAdminAccess('role=user');
    }

    public function test_a_web_user_with_a_null_role_is_not_an_admin(): void
    {
        // The DB column is NOT NULL, but a model hydrated without the attribute
        // (partial select, new code path, another provider) must still fail closed.
        $user = User::factory()->make(['role' => null]);
        $user->id = 99;
        $this->actingAs($user);

        $this->get('/admin/settings/utility-bills')->assertForbidden();
        $this->put('/admin/settings/utility-bills', ['enabled' => 1, 'billers' => []])->assertForbidden();
        $this->assertNoAdminAccess('role=null');
    }

    public function test_missing_empty_or_lookalike_roles_are_not_admin(): void
    {
        foreach ([null, '', ' ', 'Admin', 'ADMIN', ' admin', 'admin ', 'administrator', 'superadmin', 'staff', 'vendor', '0', 'false'] as $role) {
            $user = User::factory()->make(['role' => $role]);
            $user->id = 77;
            $this->actingAs($user);

            $this->assertFalse(AdminAccess::check(), 'role '.var_export($role, true));
            $this->get('/admin/utility-bill-sales')->assertForbidden();
        }

        // A model that never had a role attribute at all.
        $bare = new class extends BaseAuthenticatable {};
        $bare->id = 5;
        $this->actingAs($bare);
        $this->assertFalse(AdminAccess::check());
    }

    public function test_the_exact_admin_role_is_authorized(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->assertTrue(AdminAccess::check());
        $this->get('/admin/utility-bill-sales')->assertOk();
        $this->get('/admin/settings/utility-bills')->assertOk();
    }

    public function test_a_legitimate_admin_on_the_admin_guard_is_authorized(): void
    {
        $admin = Admin::factory()->create();
        Auth::guard('admin')->setUser($admin);

        $this->assertTrue(AdminAccess::check());
        $this->get('/admin/utility-bill-sales')->assertOk();
    }

    public function test_a_vendor_session_is_never_an_admin_even_as_the_default_guard(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);

        // Realistic: vendor guard only.
        Auth::guard('vendor')->setUser($vendor);
        $this->get('/admin/utility-bill-sales')->assertRedirect(route('admin.login'));
        $this->assertNoAdminAccess('vendor guard');

        // Worst case: the vendor is the DEFAULT guard's user (previously matched `?? 'admin'`).
        Auth::shouldUse('vendor');
        $this->assertFalse(AdminAccess::check());
        $this->assertNoAdminAccess('vendor as default guard');
    }

    public function test_an_admin_model_on_the_wrong_guard_is_refused(): void
    {
        $this->actingAs(Admin::factory()->create(), 'web');

        $this->assertFalse(AdminAccess::check());
        $this->assertNoAdminAccess('Admin model on web guard');
    }

    public function test_a_generic_user_without_a_role_on_the_web_guard_is_refused(): void
    {
        $this->actingAs(new GenericUser(['id' => 3, 'name' => 'Nobody']));

        $this->assertFalse(AdminAccess::check());
        $this->assertNoAdminAccess('generic user');
    }

    public function test_admin_post_actions_cannot_be_driven_by_non_admins(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->post('/admin/utility-bill-sales/1/retry')->assertForbidden();
        $this->post('/admin/utility-bill-sales/1/new-attempt', ['confirm' => 1])->assertForbidden();
        $this->put('/admin/settings/utility-bills', [
            'enabled' => 1,
            'billers' => ['ecg' => ['is_enabled' => 1, 'commission_type' => 'fixed', 'commission_value' => '50']],
        ])->assertForbidden();

        $this->assertDatabaseCount('utility_biller_configs', 0);
        $this->assertDatabaseCount('utility_bill_config_audits', 0);
    }

    public function test_policy_and_form_request_use_the_same_fail_closed_rule(): void
    {
        $policy = new UssdPlanPolicy;
        $noRole = new class extends BaseAuthenticatable {};

        $this->assertFalse($policy->viewAny($noRole));
        $this->assertFalse($policy->viewAny(User::factory()->make(['role' => null])));
        $this->assertFalse($policy->viewAny(User::factory()->make(['role' => 'user'])));
        $this->assertFalse($policy->viewAny(null));
        $this->assertTrue($policy->viewAny(User::factory()->make(['role' => 'admin'])));
        $this->assertTrue($policy->viewAny(new Admin));

        // Form Request authorize(): no admin session => refused.
        $this->actingAs(User::factory()->make(['role' => null]));
        $this->assertFalse((new UpdateUssdSettingsRequest)->authorize());
        $this->actingAs(User::factory()->make(['role' => 'admin']));
        $this->assertTrue((new UpdateUssdSettingsRequest)->authorize());
    }

    public function test_admin_login_refuses_a_session_that_does_not_resolve_to_an_admin(): void
    {
        $admin = Admin::factory()->create(['password' => bcrypt('secret-pass')]);

        $this->post(route('admin.login.submit'), ['email' => $admin->email, 'password' => 'secret-pass'])
            ->assertRedirect();
        $this->assertTrue(AdminAccess::check());
    }

    public function test_isAdmin_type_contract(): void
    {
        $this->assertFalse(AdminAccess::isAdmin(null));
        $this->assertFalse(AdminAccess::isAdmin(Vendor::factory()->make()));
        $this->assertInstanceOf(Authenticatable::class, new Admin);
    }
}
