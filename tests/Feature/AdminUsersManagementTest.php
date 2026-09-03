<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\TestCase;

class AdminUsersManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_see_standard_admin_edit_and_delete_actions(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.index'))
            ->assertOk()
            ->assertSee(__('admin.button.edit'))
            ->assertSee(__('admin.button.delete'))
            ->assertSee(route('admin.admin-users.delete', ['adminId' => $standardAdmin->id]), false);
    }

    public function test_current_super_admin_can_see_own_edit_action_but_not_delete_action(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.index'))
            ->assertOk()
            ->assertSee(__('admin.button.edit'))
            ->assertDontSee(route('admin.admin-users.delete', ['adminId' => $superAdmin->id]), false);
    }

    public function test_admin_user_index_links_to_dedicated_create_and_edit_pages(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.index'))
            ->assertOk()
            ->assertSee(route('admin.admin-users.create'), false)
            ->assertSee(route('admin.admin-users.edit', ['adminId' => $standardAdmin->id]), false)
            ->assertDontSee('id="create-admin-modal"', false)
            ->assertDontSee('id="edit-admin-modal"', false)
            ->assertDontSee('showCreateAdminModal', false)
            ->assertDontSee('showEditAdminModal', false);
    }

    public function test_super_admin_can_open_admin_user_create_and_edit_forms(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');
        $this->actingAs($superAdmin, 'admin');

        $this->get(route('admin.admin-users.create'))
            ->assertOk()
            ->assertSee('action="'.route('admin.admin-users.store').'"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="confirm_password"', false)
            ->assertSee('name="_token"', false);

        $this->get(route('admin.admin-users.edit', ['adminId' => $standardAdmin->id]))
            ->assertOk()
            ->assertSee('action="'.route('admin.admin-users.update', ['adminId' => $standardAdmin->id]).'"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="confirm_password"', false)
            ->assertDontSee('secret-123', false);
    }

    public function test_admin_user_form_pages_keep_super_admin_authorization(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');

        $this->get(route('admin.admin-users.create'))
            ->assertRedirect(route('admin.login'));

        $this->actingAs($standardAdmin, 'admin')
            ->get(route('admin.admin-users.create'))
            ->assertForbidden();
        $this->get(route('admin.admin-users.edit', ['adminId' => $superAdmin->id]))
            ->assertForbidden();
    }

    public function test_admin_user_validation_does_not_flash_password_fields(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.create'))
            ->post(route('admin.admin-users.store'), [
                'username' => '',
                'display_name' => 'Retry admin',
                'email' => 'retry@example.com',
                'password' => 'password-must-stay-hidden',
                'confirm_password' => 'password-must-stay-hidden',
            ])
            ->assertRedirect(route('admin.admin-users.create'))
            ->assertSessionHasErrors('username')
            ->assertSessionHasInput('display_name', 'Retry admin');

        $oldInput = session()->getOldInput();
        $this->assertArrayNotHasKey('password', $oldInput);
        $this->assertArrayNotHasKey('confirm_password', $oldInput);
    }

    public function test_admin_user_forms_render_array_shaped_old_input_without_rendering_passwords(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');
        $oldInput = [
            'username' => ['unexpected'],
            'display_name' => ['unexpected'],
            'email' => ['unexpected'],
            'status' => ['inactive'],
            'password' => 'old-password-must-stay-hidden',
            'confirm_password' => 'old-password-must-stay-hidden',
        ];

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['_old_input' => $oldInput])
            ->get(route('admin.admin-users.create'))
            ->assertOk()
            ->assertDontSee('old-password-must-stay-hidden', false);

        $this->withSession(['_old_input' => $oldInput])
            ->get(route('admin.admin-users.edit', ['adminId' => $standardAdmin->id]))
            ->assertOk()
            ->assertDontSee('old-password-must-stay-hidden', false);
    }

    public function test_admin_user_form_errors_are_accessibly_associated_with_every_validated_control(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');
        $this->actingAs($superAdmin, 'admin');
        $fields = ['username', 'display_name', 'email', 'status', 'password', 'confirm_password'];

        $errors = (new ViewErrorBag)->put(
            'default',
            new MessageBag(array_fill_keys($fields, 'Accessible validation error')),
        );

        $response = $this
            ->withSession(['errors' => $errors])
            ->get(route('admin.admin-users.edit', ['adminId' => $standardAdmin->id]));

        $response->assertOk();
        $html = $response->getContent();

        foreach ($fields as $field) {
            $errorId = 'admin-user-'.str_replace('_', '-', $field).'-error';
            $this->assertSame(1, substr_count($html, 'id="'.$errorId.'"'), $field);
            $this->assertMatchesRegularExpression('/aria-describedby="[^"]*\b'.preg_quote($errorId, '/').'\b[^"]*"/', $html, $field);
        }

        $this->assertSame(count($fields), substr_count($html, 'aria-invalid="true"'));
        $this->assertStringContainsString(
            'aria-describedby="admin-user-password-help admin-user-password-error"',
            $html,
        );
        $this->assertStringContainsString(
            'aria-describedby="admin-user-password-help admin-user-confirm-password-error"',
            $html,
        );

        $selfErrors = (new ViewErrorBag)->put(
            'default',
            new MessageBag(['status' => 'Accessible validation error']),
        );
        $selfHtml = $this
            ->withSession(['errors' => $selfErrors])
            ->get(route('admin.admin-users.edit', ['adminId' => $superAdmin->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<input id="status" type="text" readonly aria-readonly="true"[^>]*aria-invalid="true" aria-describedby="admin-user-status-error"/',
            $selfHtml,
        );
        $this->assertSame(1, substr_count($selfHtml, 'id="admin-user-status-error"'));
    }

    public function test_admin_user_create_exception_is_reported_without_exposing_internal_details(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $sensitiveMarker = 'SQLSTATE[HY000] create-internal-secret';
        Exceptions::fake();
        Admin::creating(static function (Admin $admin) use ($sensitiveMarker): void {
            if ($admin->username === 'create_failure_admin') {
                throw new RuntimeException($sensitiveMarker);
            }
        });

        $response = $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.create'))
            ->post(route('admin.admin-users.store'), [
                'username' => 'create_failure_admin',
                'display_name' => 'Create failure admin',
                'email' => 'create-failure@example.com',
                'password' => 'create-failure-password',
                'confirm_password' => 'create-failure-password',
            ]);

        $response->assertRedirect(route('admin.admin-users.create'))
            ->assertSessionHasErrors()
            ->assertSessionDoesntHaveErrors(['username', 'password']);
        $this->assertSame([__('admin.admin_users.message.create_error')], session('errors')->all());
        $this->assertStringNotContainsString($sensitiveMarker, implode(' ', session('errors')->all()));
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertArrayNotHasKey('confirm_password', session()->getOldInput());
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === $sensitiveMarker,
        );
    }

    public function test_admin_user_update_exception_is_reported_without_exposing_internal_details(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');
        $sensitiveMarker = 'SQLSTATE[23000] update-internal-secret';
        Exceptions::fake();
        Admin::updating(static function (Admin $admin) use ($sensitiveMarker): void {
            if ($admin->username === 'update_failure_admin') {
                throw new RuntimeException($sensitiveMarker);
            }
        });

        $response = $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $standardAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $standardAdmin->id]), [
                'username' => 'update_failure_admin',
                'display_name' => 'Update failure admin',
                'email' => 'update-failure@example.com',
                'status' => 'active',
                'password' => 'update-failure-password',
                'confirm_password' => 'update-failure-password',
                'ai_config_mode' => 'independent',
                'expected_ai_config_access_version' => 1,
                'expected_shared_ai_config_owner_id' => null,
            ]);

        $response->assertRedirect(route('admin.admin-users.edit', ['adminId' => $standardAdmin->id]))
            ->assertSessionHasErrors()
            ->assertSessionDoesntHaveErrors(['username', 'password']);
        $this->assertSame([__('admin.admin_users.message.update_error')], session('errors')->all());
        $this->assertStringNotContainsString($sensitiveMarker, implode(' ', session('errors')->all()));
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertArrayNotHasKey('confirm_password', session()->getOldInput());
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === $sensitiveMarker,
        );
    }

    public function test_admin_user_toggle_exception_is_reported_without_exposing_internal_details(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('toggle_failure_admin', 'admin');
        $sensitiveMarker = 'SQLSTATE[40001] toggle-internal-secret';
        Exceptions::fake();
        Admin::updating(static function (Admin $admin) use ($sensitiveMarker): void {
            if ($admin->username === 'toggle_failure_admin') {
                throw new RuntimeException($sensitiveMarker);
            }
        });

        $response = $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.index'))
            ->post(route('admin.admin-users.toggle-status', ['adminId' => $standardAdmin->id]), [
                'next_status' => 'inactive',
            ]);

        $response->assertRedirect(route('admin.admin-users.index'))
            ->assertSessionHasErrors();
        $this->assertSame([__('admin.admin_users.message.toggle_error')], session('errors')->all());
        $this->assertStringNotContainsString($sensitiveMarker, implode(' ', session('errors')->all()));
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === $sensitiveMarker,
        );
    }

    public function test_admin_user_delete_exception_is_reported_without_exposing_internal_details(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('delete_failure_admin', 'admin');
        $sensitiveMarker = 'SQLSTATE[23000] delete-internal-secret';
        Exceptions::fake();
        Admin::deleting(static function (Admin $admin) use ($sensitiveMarker): void {
            if ($admin->username === 'delete_failure_admin') {
                throw new RuntimeException($sensitiveMarker);
            }
        });

        $response = $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.index'))
            ->post(route('admin.admin-users.delete', ['adminId' => $standardAdmin->id]));

        $response->assertRedirect(route('admin.admin-users.index'))
            ->assertSessionHasErrors();
        $this->assertSame([__('admin.admin_users.message.delete_error')], session('errors')->all());
        $this->assertStringNotContainsString($sensitiveMarker, implode(' ', session('errors')->all()));
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === $sensitiveMarker,
        );
    }

    public function test_admin_user_id_routes_reject_non_numeric_parameters(): void
    {
        $this->actingAs($this->createAdmin('root_admin', 'super_admin'), 'admin');

        $this->get(route('admin.admin-users.edit', ['adminId' => 'not-a-number']))->assertNotFound();
        $this->post(route('admin.admin-users.update', ['adminId' => 'not-a-number']))->assertNotFound();
        $this->post(route('admin.admin-users.toggle-status', ['adminId' => 'not-a-number']))->assertNotFound();
        $this->post(route('admin.admin-users.delete', ['adminId' => 'not-a-number']))->assertNotFound();
    }

    public function test_admin_user_id_routes_reject_zero_and_oversized_numeric_parameters(): void
    {
        $this->actingAs($this->createAdmin('root_admin', 'super_admin'), 'admin');

        foreach (['0', '9999999999999999999'] as $adminId) {
            $this->get(route('admin.admin-users.edit', ['adminId' => $adminId]))->assertNotFound();
            $this->post(route('admin.admin-users.update', ['adminId' => $adminId]))->assertNotFound();
            $this->post(route('admin.admin-users.toggle-status', ['adminId' => $adminId]))->assertNotFound();
            $this->post(route('admin.admin-users.delete', ['adminId' => $adminId]))->assertNotFound();
        }
    }

    public function test_admin_user_id_routes_return_not_found_for_a_missing_positive_integer(): void
    {
        $this->actingAs($this->createAdmin('root_admin', 'super_admin'), 'admin');
        $adminId = '999999';

        $this->get(route('admin.admin-users.edit', ['adminId' => $adminId]))->assertNotFound();
        $this->post(route('admin.admin-users.update', ['adminId' => $adminId]))->assertNotFound();
        $this->post(route('admin.admin-users.toggle-status', ['adminId' => $adminId]))->assertNotFound();
        $this->post(route('admin.admin-users.delete', ['adminId' => $adminId]))->assertNotFound();
    }

    public function test_updating_an_admin_with_blank_password_preserves_the_existing_password(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');
        $passwordHash = $standardAdmin->password;

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.update', ['adminId' => $standardAdmin->id]), [
                'username' => 'editor_admin',
                'display_name' => 'Editor admin',
                'email' => 'editor-admin@example.com',
                'status' => 'active',
                'password' => '',
                'confirm_password' => '',
                'ai_config_mode' => 'independent',
                'expected_ai_config_access_version' => 1,
                'expected_shared_ai_config_owner_id' => null,
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame($passwordHash, $standardAdmin->fresh()->password);
    }

    public function test_current_super_admin_can_update_own_profile_and_password_without_disabling_self(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.update', ['adminId' => $superAdmin->id]), [
                'username' => 'root_owner',
                'display_name' => 'Root Owner',
                'email' => 'root-owner@example.com',
                'status' => 'inactive',
                'password' => 'new-root-secret-123',
                'confirm_password' => 'new-root-secret-123',
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $superAdmin->refresh();

        $this->assertSame('root_owner', $superAdmin->username);
        $this->assertSame('Root Owner', $superAdmin->display_name);
        $this->assertSame('root-owner@example.com', $superAdmin->email);
        $this->assertSame('active', $superAdmin->status);
        $this->assertTrue(Hash::check('new-root-secret-123', $superAdmin->password));
    }

    public function test_super_admin_can_update_standard_admin_profile_and_password(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.update', ['adminId' => $standardAdmin->id]), [
                'username' => 'editor_ops',
                'display_name' => 'Editor Ops',
                'email' => 'editor-ops@example.com',
                'status' => 'inactive',
                'password' => 'new-secret-123',
                'confirm_password' => 'new-secret-123',
                'ai_config_mode' => 'independent',
                'expected_ai_config_access_version' => 1,
                'expected_shared_ai_config_owner_id' => null,
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $standardAdmin->refresh();

        $this->assertSame('editor_ops', $standardAdmin->username);
        $this->assertSame('Editor Ops', $standardAdmin->display_name);
        $this->assertSame('editor-ops@example.com', $standardAdmin->email);
        $this->assertSame('inactive', $standardAdmin->status);
        $this->assertTrue(Hash::check('new-secret-123', $standardAdmin->password));
    }

    public function test_updating_an_admin_password_revokes_their_existing_credentials(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('credential_owner', 'admin');
        $standardAdmin->forceFill(['remember_token' => 'old-remember-token'])->save();
        $tokenId = $standardAdmin->createToken('existing-token', ['catalog:read'])->accessToken->id;

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.update', ['adminId' => $standardAdmin->id]), [
                'username' => 'credential_owner',
                'display_name' => 'Credential Owner',
                'email' => 'credential-owner@example.com',
                'status' => 'active',
                'password' => 'new-secret-123',
                'confirm_password' => 'new-secret-123',
                'ai_config_mode' => 'independent',
                'expected_ai_config_access_version' => 1,
                'expected_shared_ai_config_owner_id' => null,
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $standardAdmin->refresh();

        $this->assertSame(2, $standardAdmin->auth_version);
        $this->assertNotSame('old-remember-token', $standardAdmin->remember_token);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_toggling_admin_status_revokes_their_existing_credentials(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('status_owner', 'admin');
        $standardAdmin->forceFill(['remember_token' => 'old-remember-token'])->save();
        $tokenId = $standardAdmin->createToken('existing-token', ['catalog:read'])->accessToken->id;

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.toggle-status', ['adminId' => $standardAdmin->id]), [
                'next_status' => 'inactive',
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $standardAdmin->refresh();

        $this->assertSame('inactive', $standardAdmin->status);
        $this->assertSame(2, $standardAdmin->auth_version);
        $this->assertNotSame('old-remember-token', $standardAdmin->remember_token);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_super_admin_can_delete_standard_admin(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');
        $tokenId = $standardAdmin->createToken('existing-token', ['catalog:read'])->accessToken->id;

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.delete', ['adminId' => $standardAdmin->id]))
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertDatabaseMissing('admins', [
            'id' => $standardAdmin->id,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    private function createAdmin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
