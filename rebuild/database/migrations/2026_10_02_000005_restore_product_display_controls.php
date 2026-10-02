<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('initials', 4)->nullable()->after('publisher');
            $table->string('accent_color', 7)->nullable()->after('initials');
            $table->boolean('instant')->default(false)->after('accent_color');
            $table->boolean('package_tabs_enabled')->default(false)->after('instant');
            $table->json('package_tabs')->nullable()->after('package_tabs_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['initials', 'accent_color', 'instant', 'package_tabs_enabled', 'package_tabs']);
        });
    }
};
