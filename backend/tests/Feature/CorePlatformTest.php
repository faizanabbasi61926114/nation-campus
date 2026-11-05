<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CorePlatformTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrg(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);
    }

    public function test_login_returns_token_and_permissions(): void
    {
        $this->seedOrg();
        $res = $this->postJson('/api/v1/auth/login', ['email' => 'admin@nationcampus.edu.pk', 'password' => 'Password123!']);
        $res->assertOk()->assertJsonPath('user.email', 'admin@nationcampus.edu.pk');
        $this->assertNotEmpty($res->json('token'));
        $this->assertContains('*', $res->json('user.permissions'));
        $res->assertJsonPath('user.is_head_office', true);
    }

    public function test_login_fails_for_bad_password_and_disabled_account(): void
    {
        $this->seedOrg();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@nationcampus.edu.pk', 'password' => 'wrong'])
            ->assertStatus(422);
        User::firstWhere('email', 'reception.khi@sunrise.edu.pk')->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => 'reception.khi@sunrise.edu.pk', 'password' => 'Password123!'])
            ->assertStatus(403);
    }

    public function test_permission_middleware_blocks_unauthorized_role(): void
    {
        $this->seedOrg();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'teacher.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        // class_teacher has no campuses.manage
        $this->withToken($token)->postJson('/api/v1/campuses', ['name' => 'Rogue', 'code' => 'RG'])->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/audit-logs')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/campuses')->assertOk();
    }

    public function test_campus_scoping_denies_cross_campus_access(): void
    {
        $this->seedOrg();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'principal.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $isb = Campus::firstWhere('code', 'ISB')->id;
        $this->withToken($token)->withHeaders(['X-Campus-Id' => $isb])->getJson('/api/v1/campuses')->assertStatus(403);
        // own campus allowed and profile lists only Lahore
        $this->flushHeaders();
        $me = $this->withToken($token)->getJson('/api/v1/auth/me');
        $me->assertOk();
        $this->assertSame(['LHR'], collect($me->json('campuses'))->pluck('code')->all());
    }

    public function test_settings_override_per_campus(): void
    {
        $this->seedOrg();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'principal.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $lhr = Campus::firstWhere('code', 'LHR')->id;
        $this->withToken($token)->putJson('/api/v1/settings', [
            'campus_id' => $lhr,
            'settings' => [['key' => 'fees.late_fee_percent', 'value' => 5]],
        ])->assertOk();
        $this->assertSame(5, Setting::getFor(1, $lhr, 'fees.late_fee_percent'));
        $this->assertSame(2, Setting::getFor(1, Campus::firstWhere('code', 'ISB')->id, 'fees.late_fee_percent'));
    }

    public function test_role_change_revokes_active_tokens(): void
    {
        $this->seedOrg();
        $user = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk');
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'teacher.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $admin = $this->postJson('/api/v1/auth/login', ['email' => 'admin@nationcampus.edu.pk', 'password' => 'Password123!'])->json('token');
        $role = Role::firstWhere('slug', 'class_teacher');
        $this->withToken($admin)->putJson('/api/v1/users/' . $user->id, ['roles' => [['role_id' => $role->id, 'campus_id' => null]]])->assertOk();
        $this->assertSame(0, $user->tokens()->count(), 'tokens should be revoked');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_audit_log_records_create_and_update(): void
    {
        $this->seedOrg();
        $admin = $this->postJson('/api/v1/auth/login', ['email' => 'admin@nationcampus.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->withToken($admin)->postJson('/api/v1/campuses', ['name' => 'New Campus Multan', 'code' => 'MUX', 'city' => 'Multan'])
            ->assertCreated();
        $campus = Campus::firstWhere('code', 'MUX');
        $this->withToken($admin)->putJson('/api/v1/campuses/' . $campus->id, ['city' => 'Multan Cantonment'])->assertOk();

        $logs = $this->withToken($admin)->getJson('/api/v1/audit-logs')->assertOk()->json('data');
        $actions = collect($logs)->where('auditable_type', Campus::class)->pluck('action')->all();
        $this->assertContains('created', $actions);
        $this->assertContains('updated', $actions);
    }

    public function test_super_admin_can_create_campus_with_scoped_principal_login(): void
    {
        $this->seedOrg();
        $admin = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@nationcampus.edu.pk',
            'password' => 'Password123!',
        ])->json('token');

        $this->withToken($admin)->postJson('/api/v1/campuses', [
            'name' => 'Nation Campus Multan',
            'code' => 'MUX',
            'city' => 'Multan',
            'initial_user' => [
                'name' => 'Multan Principal',
                'email' => 'principal.mux@nationcampus.edu.pk',
                'password' => 'CampusPass123!',
            ],
        ])->assertCreated()->assertJsonPath('data.code', 'MUX');

        $campus = Campus::firstWhere('code', 'MUX');
        $principal = User::firstWhere('email', 'principal.mux@nationcampus.edu.pk');
        $this->assertSame($campus->id, $principal->campus_id);
        $this->assertTrue(Hash::check('CampusPass123!', $principal->password));
        $this->assertSame(
            [$campus->id],
            $principal->roleAssignments->pluck('pivot.campus_id')->all(),
        );

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'principal.mux@nationcampus.edu.pk',
            'password' => 'CampusPass123!',
        ])->assertOk();
        $this->assertSame(['MUX'], collect($login->json('user.campuses'))->pluck('code')->all());
        $this->assertContains('principal', $login->json('user.roles'));
    }

    public function test_campus_school_type_defaults_is_checked_and_reaches_the_profile(): void
    {
        $this->seedOrg();
        $admin = $this->postJson('/api/v1/auth/login', ['email' => 'admin@nationcampus.edu.pk', 'password' => 'Password123!'])->json('token');

        $this->withToken($admin)->postJson('/api/v1/campuses', ['name' => 'Early Years Campus', 'code' => 'EYC'])
            ->assertCreated()->assertJsonPath('data.school_type', 'secondary');

        $this->withToken($admin)->postJson('/api/v1/campuses', ['name' => 'Unknown Type', 'code' => 'UNK', 'school_type' => 'kindergarten'])
            ->assertStatus(422)->assertJsonValidationErrors('school_type');

        $campus = Campus::firstWhere('code', 'EYC');
        $this->withToken($admin)->putJson('/api/v1/campuses/' . $campus->id, ['school_type' => 'montessori'])
            ->assertOk()->assertJsonPath('data.school_type', 'montessori');

        $profile = $this->withToken($admin)->getJson('/api/v1/auth/me')->assertOk();
        $row = collect($profile->json('campuses'))->firstWhere('code', 'EYC');
        $this->assertSame('montessori', $row['school_type']);
    }

    public function test_multi_org_isolation(): void
    {
        $this->seedOrg();
        $org2 = Organization::create(['name' => 'Second Trust', 'code' => 'SECOND']);
        // Move a seeded campus's parent org check: admin of org1 must not see org2 campuses
        Campus::create(['organization_id' => $org2->id, 'name' => 'Org2 Campus', 'code' => 'O2']);
        $admin = $this->postJson('/api/v1/auth/login', ['email' => 'admin@nationcampus.edu.pk', 'password' => 'Password123!'])->json('token');
        $codes = collect($this->withToken($admin)->getJson('/api/v1/campuses')->assertOk()->json('data'))->pluck('code');
        $this->assertFalse($codes->contains('O2'));
    }
}
