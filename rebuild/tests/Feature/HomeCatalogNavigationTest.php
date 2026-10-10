<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class HomeCatalogNavigationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_category_navigation_returns_only_changed_props_for_inertia_partial_visit(): void
    {
        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Catalog/Index',
            'X-Inertia-Partial-Data' => 'products,filters',
        ])->get('/?category=game');

        $response->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Catalog/Index')
            ->assertJsonPath('props.filters.category', 'game')
            ->assertJsonStructure(['props' => ['products' => ['data'], 'filters']])
            ->assertJsonMissingPath('props.popularProducts')
            ->assertJsonMissingPath('props.banners')
            ->assertJsonMissingPath('props.categories');
    }

    public function test_manual_filter_supports_the_same_partial_update_contract(): void
    {
        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Catalog/Index',
            'X-Inertia-Partial-Data' => 'products,filters',
        ])->get('/?mode=manual');

        $response->assertOk()
            ->assertJsonPath('component', 'Catalog/Index')
            ->assertJsonPath('props.filters.mode', 'manual')
            ->assertJsonStructure(['props' => ['products' => ['data'], 'filters']]);
    }

    public function test_home_tabs_and_pagination_preserve_inertia_component_state(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Catalog/Index.vue'));

        $this->assertIsString($source);
        // All, Manual, category list, and pagination must be same-page partial visits.
        $this->assertSame(
            4,
            substr_count($source, ':only="[\'products\',\'filters\']" preserve-state preserve-scroll replace')
        );
        $this->assertStringContainsString(
            "replace:true,only:['products','filters']",
            $source,
            'Search must also update products without remounting the homepage.'
        );
    }
}
