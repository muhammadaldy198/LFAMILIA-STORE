<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the membership-removal migration reverses cleanly and re-applies.
 *
 * No DatabaseTransactions here: MySQL DDL commits implicitly, so a wrapping
 * transaction could not roll the schema back. Instead the test restores the
 * schema itself in `finally`, leaving the test database as it found it.
 */
class RemoveMembershipTierMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_17_000001_remove_membership_tier_system.php';

    private const USER_COLUMNS = [
        'membership_tier_code',
        'membership_mode',
        'membership_override_code',
        'membership_progress_bonus_idr',
    ];

    public function test_up_leaves_no_membership_table_or_user_columns(): void
    {
        $this->assertFalse(Schema::hasTable('membership_tiers'));
        foreach (self::USER_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), $column);
        }
    }

    public function test_down_restores_the_original_schema_and_seed_then_up_removes_it_again(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        try {
            $this->assertSame(
                ['BASIC', 'SILVER', 'GOLD', 'DIAMOND', 'PLATINUM', 'MAFIA'],
                DB::table('membership_tiers')->orderBy('rank')->pluck('code')->all()
            );
            $this->assertSame(
                6,
                DB::table('membership_tiers')->whereNull('requirements')->whereNull('benefits')->where('is_active', true)->count()
            );

            // Original column order: tier, mode, override, bonus follow google_sub.
            $columns = Schema::getColumnListing('users');
            $start = array_search('google_sub', $columns, true);
            $this->assertSame(self::USER_COLUMNS, array_slice($columns, $start + 1, 4));

            $foreignColumns = collect(Schema::getForeignKeys('users'))->flatMap(fn (array $key): array => $key['columns'])->all();
            $this->assertContains('membership_tier_code', $foreignColumns);
            $this->assertContains('membership_override_code', $foreignColumns);
        } finally {
            $migration->up();
        }

        $this->assertFalse(Schema::hasTable('membership_tiers'));
        foreach (self::USER_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), $column);
        }
    }
}
