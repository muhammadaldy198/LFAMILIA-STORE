<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\NicknameGameCode;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminNicknameToolsTest extends TestCase
{
    use DatabaseTransactions;

    private function login(string $role = 'SUPER_ADMIN'): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Validation Admin',
            'email' => 'validation-'.bin2hex(random_bytes(5)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => $role,
            'permissions' => $role === 'ADMIN' ? ['dashboard.view'] : null,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function product(string $gameCode): Product
    {
        return Product::create([
            'category_id' => Category::where('slug', 'game')->value('id'),
            'name' => 'Validation Product '.bin2hex(random_bytes(3)),
            'slug' => 'validation-product-'.bin2hex(random_bytes(5)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'nickname_check_enabled' => true,
            'nickname_game_code' => $gameCode,
            'nickname_user_field_key' => 'user_id',
            'nickname_server_field_key' => 'zone_id',
            'is_active' => true,
        ]);
    }

    public function test_game_code_management_is_super_admin_only(): void
    {
        $this->login('ADMIN');

        $this->postJson('/admin/nickname-tools/game-codes', [
            'name' => 'Denied Game',
            'code' => 'denied-game',
            'requires_server' => false,
            'requires_region_check' => false,
            'is_active' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('nickname_game_codes', ['code' => 'denied-game']);
    }

    public function test_super_admin_can_create_edit_and_cascade_code_to_products(): void
    {
        $this->login();

        $created = $this->postJson('/admin/nickname-tools/game-codes', [
            'name' => 'Editable Game',
            'code' => 'editable-game',
            'requires_server' => false,
            'requires_region_check' => true,
            'is_active' => true,
        ])->assertCreated();

        $row = NicknameGameCode::where('code', 'editable-game')->firstOrFail();
        $this->assertTrue($row->requires_server);
        $this->assertTrue($row->requires_region_check);
        $this->assertNotEmpty($created->json('game_codes'));

        $product = $this->product('editable-game');

        $this->putJson('/admin/nickname-tools/game-codes/'.$row->id, [
            'name' => 'Editable Game Baru',
            'code' => 'editable-game-baru',
            'requires_server' => false,
            'requires_region_check' => false,
            'is_active' => true,
            'sort_order' => $row->sort_order,
        ])->assertOk();

        $row->refresh();
        $this->assertSame('editable-game-baru', $row->code);
        $this->assertSame('editable-game-baru', $product->fresh()->nickname_game_code);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'nickname_game_code.updated')
            ->where('target_id', (string) $row->id)
            ->count());
    }

    public function test_used_game_code_cannot_be_deleted_until_product_reference_is_removed(): void
    {
        $this->login();
        $row = NicknameGameCode::where('code', 'free-fire')->firstOrFail();
        $product = $this->product($row->code);

        $this->deleteJson('/admin/nickname-tools/game-codes/'.$row->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game_code']);

        $this->assertDatabaseHas('nickname_game_codes', ['id' => $row->id]);

        $product->update([
            'nickname_check_enabled' => false,
            'nickname_game_code' => null,
            'nickname_user_field_key' => null,
            'nickname_server_field_key' => null,
        ]);

        $this->deleteJson('/admin/nickname-tools/game-codes/'.$row->id)->assertOk();
        $this->assertDatabaseMissing('nickname_game_codes', ['id' => $row->id]);
    }

    public function test_reorder_requires_complete_set_and_persists_new_order(): void
    {
        $this->login();

        $rows = NicknameGameCode::query()->orderBy('sort_order')->orderBy('name')->get();
        $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertGreaterThanOrEqual(2, count($ids));

        $invalid = array_slice($ids, 0, 2);
        $this->putJson('/admin/nickname-tools/game-codes/reorder', [
            'ordered_ids' => $invalid,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['ordered_ids']);

        [$ids[0], $ids[1]] = [$ids[1], $ids[0]];

        $this->putJson('/admin/nickname-tools/game-codes/reorder', [
            'ordered_ids' => $ids,
        ])->assertOk();

        $actual = NicknameGameCode::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertSame($ids, $actual);
    }

    public function test_validation_page_exposes_non_secret_config_only(): void
    {
        $this->login();
        IntegrationCredential::create([
            'code' => 'kokinpay',
            'config_ciphertext' => [
                'api_key' => 'must-not-leak-secret',
                'base_url' => 'https://validation.example.test',
                'nickname_path' => '/nickname',
                'region_path' => '/region',
                'pln_path' => '/pln',
            ],
            'is_active' => true,
        ]);

        $response = $this->get('/admin/nickname-tools')->assertOk();

        $response->assertDontSee('must-not-leak-secret');
        $response->assertSee('validation.example.test');
    }
}
