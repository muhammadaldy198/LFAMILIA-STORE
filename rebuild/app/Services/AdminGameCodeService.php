<?php

namespace App\Services;

use App\Models\NicknameGameCode;
use App\Models\Product;

/**
 * Read model for the admin game-code list: every nickname game code with
 * its live product usage, shown on the Kode Game admin page.
 *
 * This service used to also run manual nickname/region/PLN lookups against
 * the retired account-validation provider (kokinpay). Those lookups were
 * removed with the provider on the owner's order (11 Nov 2026); customer
 * checkout validation runs through H2H (see NicknameService), and this
 * class now only serves the game-code registry the checkout reads.
 */
class AdminGameCodeService
{
    /**
     * @return array<int, array{id:int,name:string,code:string,supports_nickname_check:bool,requires_server:bool,requires_region_check:bool,is_active:bool,sort_order:int,product_count:int,nickname_product_count:int}>
     */
    public function gameCodes(): array
    {
        $usage = Product::query()
            ->whereNotNull('nickname_game_code')
            ->selectRaw('nickname_game_code, COUNT(*) as aggregate')
            ->groupBy('nickname_game_code')
            ->pluck('aggregate', 'nickname_game_code');

        $nicknameUsage = Product::query()
            ->where('nickname_check_enabled', true)
            ->whereNotNull('nickname_game_code')
            ->selectRaw('nickname_game_code, COUNT(*) as aggregate')
            ->groupBy('nickname_game_code')
            ->pluck('aggregate', 'nickname_game_code');

        return NicknameGameCode::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (NicknameGameCode $item): array => [
                'id' => (int) $item->id,
                'name' => $item->name,
                'code' => $item->code,
                'supports_nickname_check' => (bool) $item->supports_nickname_check,
                'requires_server' => (bool) $item->requires_server,
                'requires_region_check' => (bool) $item->requires_region_check,
                'is_active' => (bool) $item->is_active,
                'sort_order' => (int) $item->sort_order,
                'product_count' => (int) ($usage[$item->code] ?? 0),
                'nickname_product_count' => (int) ($nicknameUsage[$item->code] ?? 0),
            ])->all();
    }
}
