<?php

namespace Tests\Unit;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhoneNormalizationMigrationTest extends TestCase
{
    private string $migrationTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrationTable = 'phone_migration_users_'.bin2hex(random_bytes(5));
        Schema::create($this->migrationTable, function (Blueprint $table): void {
            $table->id();
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->index('phone_normalized', $this->migrationTable.'_phone_normalized_index');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->migrationTable);
        parent::tearDown();
    }

    public function test_migration_rebuilds_canonical_values_and_preserves_original_phone(): void
    {
        DB::table($this->migrationTable)->insert([
            ['id' => 1, 'phone' => '0812-3456-7890', 'phone_normalized' => null],
            ['id' => 2, 'phone' => '+62 813 0000 1111', 'phone_normalized' => 'stale-value'],
            ['id' => 3, 'phone' => null, 'phone_normalized' => null],
        ]);

        $this->runMigrationForTable();

        $this->assertSame('0812-3456-7890', DB::table($this->migrationTable)->where('id', 1)->value('phone'));
        $this->assertSame(CreateNewUser::normalizePhone('0812-3456-7890'), DB::table($this->migrationTable)->where('id', 1)->value('phone_normalized'));
        $this->assertSame(CreateNewUser::normalizePhone('+62 813 0000 1111'), DB::table($this->migrationTable)->where('id', 2)->value('phone_normalized'));
        $this->assertNull(DB::table($this->migrationTable)->where('id', 3)->value('phone_normalized'));
        $this->assertNotFalse(collect(Schema::getIndexes($this->migrationTable))->firstWhere('name', $this->migrationTable.'_phone_normalized_unique'));

        $this->assertSame('6281234567890', CreateNewUser::normalizePhone('081234567890'));
        $this->assertSame('6281234567890', CreateNewUser::normalizePhone('+62 81234567890'));
        $this->assertSame('6281234567890', CreateNewUser::normalizePhone('81234567890'));
        $this->assertSame('15551234567', CreateNewUser::normalizePhone('+1 555-123-4567'));
        $this->assertSame('8123456789', CreateNewUser::normalizePhone('+81 23456789'));
        $this->assertSame('390612345678', CreateNewUser::normalizePhone('+39 0612345678'));

        // Pastikan nomor internasional non-ID tidak di-expand ke variasi lokal Indonesia
        // baik ketika diprefix '+' maupun ketika diinput digit polos (contoh Jepang 81 223456789)
        $variantsWithPlus = CreateNewUser::phoneVariants('+81 223456789');
        $this->assertNotContains('6281223456789', $variantsWithPlus);
        $this->assertNotContains('081223456789', $variantsWithPlus);

        $variantsPlain = CreateNewUser::phoneVariants('81223456789');
        $this->assertNotContains('6281223456789', $variantsPlain);
        $this->assertNotContains('081223456789', $variantsPlain);
    }

    public function test_migration_aborts_on_duplicate_phones_without_deleting_or_merging_users(): void
    {
        DB::table($this->migrationTable)->insert([
            ['id' => 1, 'phone' => '081234567890', 'phone_normalized' => 'legacy-a'],
            ['id' => 2, 'phone' => '+62 81234567890', 'phone_normalized' => 'legacy-b'],
        ]);

        try {
            $this->runMigrationForTable();
            $this->fail('Expected duplicate canonical phone numbers to block the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Resolve accounts explicitly', $exception->getMessage());
        }

        $this->assertSame(2, DB::table($this->migrationTable)->count());
        $this->assertSame([
            '6281234567890',
            '6281234567890',
        ], DB::table($this->migrationTable)->orderBy('id')->pluck('phone_normalized')->all());
    }

    private function runMigrationForTable(): void
    {
        $file = database_path('migrations/2026_10_16_000001_unique_phone_normalized_on_users.php');
        $source = file_get_contents($file);
        $source = str_replace("from('users')", "from('{$this->migrationTable}')", $source);
        $source = str_replace("table('users'", "table('{$this->migrationTable}'", $source);
        $path = tempnam(sys_get_temp_dir(), 'phone-migration-');
        file_put_contents($path, $source);

        try {
            $temporaryMigration = require $path;
            $temporaryMigration->up();
        } finally {
            unlink($path);
        }
    }
}
