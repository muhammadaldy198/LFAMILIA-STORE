<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\NicknameGameCode;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAccountValidationRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function superAdmin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Account Validation Super',
            'email' => 'account-validation-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => 'SUPER_ADMIN',
            'permissions' => null,
            'is_active' => true,
        ]);
    }

    private function productUsingCode(string $code): Product
    {
        $category = Category::where('slug', 'game')->firstOrFail();

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Validation Product '.bin2hex(random_bytes(3)),
            'slug' => 'validation-product-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
            'nickname_check_enabled' => true,
            'nickname_game_code' => $code,
            'nickname_user_field_key' => 'user_id',
            'nickname_server_field_key' => 'zone_id',
        ]);
    }

    public function test_super_admin_can_manage_game_codes_with_product_safety_and_audit(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $create = $this->postJson('/admin/nickname-tools/game-codes', [
            'name' => 'Dynamic Game',
            'code' => 'dynamic-game',
            'requires_server' => false,
            'requires_region_check' => true,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('ok', true);

        $row = NicknameGameCode::where('code', 'dynamic-game')->firstOrFail();
        $this->assertTrue($row->requires_server);
        $this->assertTrue($row->requires_region_check);
        $this->assertTrue(collect($create->json('game_codes'))->contains('code', 'dynamic-game'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'nickname_game_code.created',
            'target_id' => (string) $row->id,
        ]);

        $product = $this->productUsingCode('dynamic-game');

        $this->putJson('/admin/nickname-tools/game-codes/'.$row->id, [
            'name' => 'Dynamic Game Updated',
            'code' => 'dynamic-game-v2',
            'requires_server' => true,
            'requires_region_check' => false,
            'is_active' => true,
            'sort_order' => $row->sort_order,
        ])->assertOk()
            ->assertJsonPath('ok', true);

        $row->refresh();
        $product->refresh();

        $this->assertSame('dynamic-game-v2', $row->code);
        $this->assertSame('dynamic-game-v2', $product->nickname_game_code);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'nickname_game_code.updated',
            'target_id' => (string) $row->id,
        ]);

        $this->putJson('/admin/nickname-tools/game-codes/'.$row->id, [
            'name' => $row->name,
            'code' => $row->code,
            'requires_server' => $row->requires_server,
            'requires_region_check' => $row->requires_region_check,
            'is_active' => false,
            'sort_order' => $row->sort_order,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);

        $this->deleteJson('/admin/nickname-tools/game-codes/'.$row->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game_code']);

        $product->update([
            'nickname_check_enabled' => false,
            'nickname_game_code' => null,
            'nickname_user_field_key' => null,
            'nickname_server_field_key' => null,
        ]);

        $this->putJson('/admin/nickname-tools/game-codes/'.$row->id, [
            'name' => $row->name,
            'code' => $row->code,
            'requires_server' => $row->requires_server,
            'requires_region_check' => $row->requires_region_check,
            'is_active' => false,
            'sort_order' => $row->sort_order,
        ])->assertOk();

        $this->deleteJson('/admin/nickname-tools/game-codes/'.$row->id)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('nickname_game_codes', ['id' => $row->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'nickname_game_code.deleted',
            'target_id' => (string) $row->id,
        ]);
    }

    public function test_game_code_reorder_requires_complete_collection_and_persists_order(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $ids = NicknameGameCode::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->putJson('/admin/nickname-tools/game-codes/reorder', [
            'ordered_ids' => array_slice($ids, 0, 2),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['ordered_ids']);

        $reversed = array_reverse($ids);
        $this->putJson('/admin/nickname-tools/game-codes/reorder', [
            'ordered_ids' => $reversed,
        ])->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(0, (int) NicknameGameCode::findOrFail($reversed[0])->sort_order);
        $this->assertSame(count($reversed) - 1, (int) NicknameGameCode::findOrFail($reversed[array_key_last($reversed)])->sort_order);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'nickname_game_code.reordered',
            'target_id' => 'collection',
        ]);
    }

    public function test_region_check_follows_editable_database_flag_instead_of_hardcoded_game_name(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        NicknameGameCode::where('code', 'mobile-legends')->update([
            'requires_region_check' => false,
        ]);
        NicknameGameCode::where('code', 'free-fire')->update([
            'requires_server' => true,
            'requires_region_check' => true,
        ]);

        IntegrationCredential::updateOrCreate(
            ['code' => 'kokinpay'],
            [
                'config_ciphertext' => [
                    'api_key' => 'test-secret',
                    'base_url' => 'https://validation.example.test',
                    'nickname_path' => '/nickname',
                    'region_path' => '/region',
                    'pln_path' => '/pln',
                ],
                'is_active' => true,
            ],
        );

        Http::fake([
            'https://validation.example.test/nickname' => Http::response([
                'status' => true,
                'data' => ['nickname' => 'FreeFirePlayer'],
            ]),
            'https://validation.example.test/region' => Http::response([
                'status' => true,
                'data' => ['region' => 'ID'],
            ]),
        ]);

        $this->postJson('/admin/nickname-tools/check', [
            'action' => 'region',
            'game_code' => 'free-fire',
            'user_id' => '123456',
            'server' => '9001',
        ])->assertOk()
            ->assertJsonPath('nickname', 'FreeFirePlayer')
            ->assertJsonPath('region', 'ID');

        $this->postJson('/admin/nickname-tools/check', [
            'action' => 'region',
            'game_code' => 'mobile-legends',
            'user_id' => '123456',
            'server' => '9001',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['game_code']);

        Http::assertSentCount(2);
    }

    public function test_regular_admin_cannot_mutate_account_validation_rules(): void
    {
        $admin = AdminUser::create([
            'name' => 'Regular Admin',
            'email' => 'regular-validation-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => 'ADMIN',
            'permissions' => ['providers.manage'],
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');

        $this->postJson('/admin/nickname-tools/game-codes', [
            'name' => 'Forbidden Game',
            'code' => 'forbidden-game',
            'requires_server' => false,
            'requires_region_check' => false,
            'is_active' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('nickname_game_codes', ['code' => 'forbidden-game']);
    }
}
