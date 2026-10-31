<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_package_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });
        DB::table('products')->select('id', 'package_tabs')->orderBy('id')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    $names = [];
                    $tabs = json_decode((string) ($product->package_tabs ?? '[]'), true);
                    foreach (is_array($tabs) ? $tabs : [] as $tab) {
                        $name = trim((string) $tab);
                        if ($name !== '' && ! in_array($name, $names, true)) {
                            $names[] = $name;
                        }
                    }
                    foreach (DB::table('product_packages')->where('product_id', $product->id)
                        ->orderBy('sort_order')->orderBy('id')->pluck('group_name') as $label) {
                        $name = trim((string) $label);
                        $name = $name === '' ? 'Umum' : $name;
                        if (! in_array($name, $names, true)) {
                            $names[] = $name;
                        }
                    }
                    if ($names === []) {
                        $names[] = 'Umum';
                    }
                    foreach ($names as $index => $name) {
                        DB::table('product_package_groups')->insert([
                            'product_id' => $product->id, 'name' => $name, 'sort_order' => $index,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_package_groups');
    }
};
