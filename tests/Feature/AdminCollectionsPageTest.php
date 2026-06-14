<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CaseRecord;
use App\Models\CollectionRecord;
use App\Models\EntityRecord;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\CaseTypes;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCollectionsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_guest_is_redirected_from_collection_pages(): void
    {
        $this->get(route('admin.collections.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.collections.create'))->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_manage_collections(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertDontSee(__('admin.materials.collection_manage_title'))
            ->assertSee(route('admin.collections.index'), false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.collections.store'), [
                'name' => 'Automation Equipment Test',
                'slug' => '',
                'description' => 'Automation scope',
                'status' => 'active',
                'sort_order' => 5,
            ])
            ->assertRedirect(route('admin.collections.index'));

        $collection = CollectionRecord::query()
            ->where('slug', 'automation-equipment-test')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.collections.index', ['search' => 'Automation Equipment Test']))
            ->assertOk()
            ->assertSee('Automation Equipment Test')
            ->assertSee('automation-equipment-test')
            ->assertSee('bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700', false)
            ->assertSee('border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50', false)
            ->assertDontSee('inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800', false);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.collections.update', ['collectionId' => (int) $collection->id]), [
                'name' => 'Automation Equipment Updated',
                'slug' => 'automation-equipment-updated',
                'description' => 'Updated scope',
                'status' => 'inactive',
                'sort_order' => 9,
            ])
            ->assertRedirect(route('admin.collections.edit', ['collectionId' => (int) $collection->id]));

        $this->assertDatabaseHas('collections', [
            'id' => (int) $collection->id,
            'name' => 'Automation Equipment Updated',
            'slug' => 'automation-equipment-updated',
            'status' => 'inactive',
            'sort_order' => 9,
        ]);
    }

    public function test_materials_can_be_assigned_and_filtered_by_collection(): void
    {
        $admin = $this->admin('collection_material_admin');
        $collection = CollectionRecord::query()->create([
            'name' => 'Industrial Cooling Test',
            'slug' => 'industrial-cooling-test',
            'description' => '',
            'status' => 'active',
            'sort_order' => 1,
        ]);
        $otherCollection = CollectionRecord::query()->create([
            'name' => 'Other Collection Test',
            'slug' => 'other-collection-test',
            'description' => '',
            'status' => 'active',
            'sort_order' => 2,
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-libraries.store'), [
            'name' => 'Cooling Keyword Library',
            'description' => '',
            'collection_id' => (int) $collection->id,
        ])->assertRedirect(route('admin.keyword-libraries.index'));

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.store'), [
            'name' => 'Cooling Title Library',
            'description' => '',
            'collection_id' => (int) $collection->id,
        ])->assertRedirect(route('admin.title-libraries.index'));

        $this->actingAs($admin, 'admin')->post(route('admin.image-libraries.store'), [
            'name' => 'Cooling Image Library',
            'description' => '',
            'collection_id' => (int) $collection->id,
        ])->assertRedirect(route('admin.image-libraries.index'));

        $this->actingAs($admin, 'admin')->post(route('admin.knowledge-bases.store'), [
            'name' => 'Cooling Knowledge Base',
            'description' => '',
            'content' => 'Cooling source content.',
            'import_action' => 'save',
            'collection_id' => (int) $collection->id,
        ])->assertRedirect(route('admin.knowledge-bases.index'));

        $this->actingAs($admin, 'admin')->post(route('admin.entities.store'), [
            'name' => 'SJ4060 Cooling Entity',
            'entity_type' => '产品型号',
            'aliases' => '',
            'description' => '',
            'attributes_json' => '{}',
            'source_url' => '',
            'collection_id' => (int) $collection->id,
        ])->assertRedirect(route('admin.entities.index'));

        $this->actingAs($admin, 'admin')->post(route('admin.cases.store'), [
            'title' => 'Cooling Customer Case',
            'case_type' => CaseTypes::APPLICATION_SCENARIO,
            'entity_id' => '',
            'summary' => '',
            'challenge' => '',
            'solution' => '',
            'result' => '',
            'metrics' => '',
            'source_url' => '',
            'collection_id' => (int) $collection->id,
        ])->assertRedirect(route('admin.cases.index'));

        KeywordLibrary::query()->create([
            'name' => 'Other Keyword Library',
            'description' => '',
            'keyword_count' => 0,
            'collection_id' => (int) $otherCollection->id,
        ]);

        $this->assertDatabaseHas('keyword_libraries', ['name' => 'Cooling Keyword Library', 'collection_id' => (int) $collection->id]);
        $this->assertDatabaseHas('title_libraries', ['name' => 'Cooling Title Library', 'collection_id' => (int) $collection->id]);
        $this->assertDatabaseHas('image_libraries', ['name' => 'Cooling Image Library', 'collection_id' => (int) $collection->id]);
        $this->assertDatabaseHas('knowledge_bases', ['name' => 'Cooling Knowledge Base', 'collection_id' => (int) $collection->id]);
        $this->assertDatabaseHas('entities', ['name' => 'SJ4060 Cooling Entity', 'collection_id' => (int) $collection->id]);
        $this->assertDatabaseHas('case_records', ['title' => 'Cooling Customer Case', 'collection_id' => (int) $collection->id]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.index', ['collection_id' => (int) $collection->id]))
            ->assertOk()
            ->assertSee('Cooling Keyword Library')
            ->assertDontSee('Other Keyword Library');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index', ['collection_id' => (int) $collection->id]))
            ->assertOk()
            ->assertSee('Cooling Knowledge Base');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.collections.delete', ['collectionId' => (int) $collection->id]))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('collections', ['id' => (int) $collection->id]);
    }

    public function test_collection_sidebar_is_available_on_material_list_pages(): void
    {
        $admin = $this->admin('collection_sidebar_admin');
        $collection = CollectionRecord::query()->create([
            'name' => 'Sidebar Collection Test',
            'slug' => 'sidebar-collection-test',
            'description' => '',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        foreach ([
            'admin.keyword-libraries.index',
            'admin.title-libraries.index',
            'admin.image-libraries.index',
            'admin.knowledge-bases.index',
            'admin.entities.index',
            'admin.cases.index',
        ] as $routeName) {
            $this->actingAs($admin, 'admin')
                ->get(route($routeName, ['search' => 'demo']))
                ->assertOk()
                ->assertSee('data-collection-sidebar', false)
                ->assertSee('sticky top-24 self-start', false)
                ->assertSee('Sidebar Collection Test')
                ->assertSee('search=demo', false)
                ->assertSee('collection_id='.(int) $collection->id, false);
        }
    }

    private function admin(string $username = 'collection_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Collection Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
