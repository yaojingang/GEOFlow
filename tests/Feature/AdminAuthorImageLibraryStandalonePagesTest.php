<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Services\GeoFlow\ManagedImageFileService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AdminAuthorImageLibraryStandalonePagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_author_index_links_to_standalone_create_and_edit_pages_without_form_modals(): void
    {
        $author = Author::query()->create([
            'name' => 'Standalone author',
            'email' => 'standalone-author@example.test',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.authors.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.authors.create').'"', false)
            ->assertSee('href="'.route('admin.authors.edit', ['authorId' => $author->id]).'"', false)
            ->assertDontSee('id="create-modal"', false)
            ->assertDontSee('id="edit-modal"', false)
            ->assertDontSee('showCreateModal', false)
            ->assertDontSee('showEditModal', false)
            ->assertSee('data-author-delete-form', false)
            ->assertSee('data-author-delete-submit', false)
            ->assertSee('disabled aria-disabled="true"', false);

        $this->assertStringContainsString(__('admin.authors.confirm_delete', ['name' => $author->name]), (string) $response->getContent());
    }

    public function test_author_create_and_edit_pages_render_the_shared_form_contract(): void
    {
        $author = Author::query()->create([
            'name' => 'Editable author',
            'email' => 'editable-author@example.test',
            'bio' => 'Author biography',
            'website' => 'https://example.test/author',
            'social_links' => 'https://social.example.test/author',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.authors.create'))
            ->assertOk()
            ->assertSee('action="'.route('admin.authors.store').'"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="email" type="text" inputmode="email"', false)
            ->assertSee('name="bio"', false)
            ->assertSee('name="website"', false)
            ->assertSee('name="website" type="text" inputmode="url"', false)
            ->assertSee('name="social_links"', false)
            ->assertSee('active:scale-[0.96]', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.authors.edit', ['authorId' => $author->id]))
            ->assertOk()
            ->assertSee('action="'.route('admin.authors.update', ['authorId' => $author->id]).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('value="editable-author@example.test"', false);
    }

    public function test_trashed_articles_are_counted_and_keep_their_author_protected(): void
    {
        $author = Author::query()->create(['name' => 'Trashed article author']);
        $category = Category::query()->create([
            'name' => 'Trashed article category',
            'slug' => 'trashed-article-category',
        ]);
        $article = Article::query()->create([
            'title' => 'Trashed author article',
            'slug' => 'trashed-author-article',
            'content' => 'Article retained in the trash.',
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);
        $article->delete();

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.authors.index'))
            ->assertOk()
            ->assertViewHas('authors', static function (array $authors) use ($author): bool {
                $row = collect($authors)->firstWhere('id', (int) $author->id);

                return is_array($row) && $row['trashed_count'] === 1;
            });

        $this->actingAs($admin, 'admin')
            ->from(route('admin.authors.index'))
            ->post(route('admin.authors.delete', ['authorId' => $author->id]))
            ->assertRedirect(route('admin.authors.index'))
            ->assertSessionHasErrors();

        $this->assertModelExists($author);
    }

    public function test_author_form_handles_array_old_input_without_a_server_error(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->withSession(['_old_input' => [
                'name' => ['unexpected'],
                'email' => ['unexpected'],
                'bio' => ['unexpected'],
                'website' => ['unexpected'],
                'social_links' => ['unexpected'],
            ]])
            ->get(route('admin.authors.create'))
            ->assertOk();
    }

    public function test_image_library_index_and_detail_use_standalone_create_edit_and_upload_pages(): void
    {
        $library = $this->imageLibrary();
        Image::query()->create([
            'library_id' => $library->id,
            'filename' => 'preview.png',
            'original_name' => 'preview.png',
            'file_name' => 'preview.png',
            'file_path' => 'storage/uploads/images/preview.png',
            'managed_path_hash' => app(ManagedImageFileService::class)->pathHash('storage/uploads/images/preview.png'),
            'file_size' => 1024,
            'mime_type' => 'image/png',
            'width' => 80,
            'height' => 80,
            'tags' => '',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.image-libraries.create').'"', false)
            ->assertSee('href="'.route('admin.image-libraries.edit', ['libraryId' => $library->id]).'"', false)
            ->assertSee('href="'.route('admin.image-libraries.images.create', ['libraryId' => $library->id]).'"', false)
            ->assertDontSee('id="create-modal"', false)
            ->assertDontSee('showCreateModal', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.detail', ['libraryId' => $library->id]))
            ->assertOk()
            ->assertSee('href="'.route('admin.image-libraries.edit', ['libraryId' => $library->id, 'context' => 'detail']).'"', false)
            ->assertSee('href="'.route('admin.image-libraries.images.create', ['libraryId' => $library->id]).'"', false)
            ->assertDontSee('id="upload-modal"', false)
            ->assertDontSee('id="edit-modal"', false)
            ->assertDontSee('showUploadModal', false)
            ->assertDontSee('showEditModal', false)
            ->assertSee('type="button"', false)
            ->assertSee('data-image-preview-trigger', false)
            ->assertDontSee('onclick="showImageModal', false)
            ->assertSee('data-gf-modal="image-preview"', false)
            ->assertSee('id="image-modal"', false)
            ->assertSee('role="dialog" aria-modal="true" aria-labelledby="image-title"', false)
            ->assertSee('data-dialog-close', false)
            ->assertSee('id="batch-form"', false)
            ->assertSee('data-image-delete-submit', false)
            ->assertSee('window.AdminActionDialog', false)
            ->assertDontSee('window.confirm', false);
    }

    public function test_image_library_create_edit_and_upload_pages_render_the_expected_forms(): void
    {
        $library = $this->imageLibrary();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.create'))
            ->assertOk()
            ->assertSee('action="'.route('admin.image-libraries.store').'"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="description"', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.edit', ['libraryId' => $library->id]))
            ->assertOk()
            ->assertSee('action="'.route('admin.image-libraries.update', ['libraryId' => $library->id]).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('name="context" value="index"', false)
            ->assertSee('href="'.route('admin.image-libraries.index').'"', false)
            ->assertSee('value="Standalone image library"', false);

        $upload = $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.images.create', ['libraryId' => $library->id]))
            ->assertOk()
            ->assertSee('action="'.route('admin.image-libraries.images.upload', ['libraryId' => $library->id]).'"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="images[]"', false)
            ->assertSee('multiple', false)
            ->assertSee('accept="image/jpeg,image/png,image/gif,image/webp"', false)
            ->assertSee('aria-describedby="image-upload-help image-upload-status"', false)
            ->assertDontSee('id="image-upload-error"', false)
            ->assertSee('data-image-upload-form', false)
            ->assertSee('data-image-upload-dropzone', false)
            ->assertSee('border-gray-300 bg-gray-50', false)
            ->assertSee('role="status" aria-live="polite"', false)
            ->assertSee('active:scale-[0.96]', false);

        $this->assertDoesNotMatchRegularExpression('/<input[^>]+type="file"[^>]+value=/i', (string) $upload->getContent());
    }

    public function test_image_library_edit_preserves_only_the_known_entry_context(): void
    {
        $library = $this->imageLibrary();
        $admin = $this->admin();
        $detailEditUrl = route('admin.image-libraries.edit', [
            'libraryId' => $library->id,
            'context' => 'detail',
        ]);

        $this->actingAs($admin, 'admin')
            ->get($detailEditUrl)
            ->assertOk()
            ->assertSee('name="context" value="detail"', false)
            ->assertSee('href="'.route('admin.image-libraries.detail', ['libraryId' => $library->id]).'"', false);

        $this->actingAs($admin, 'admin')
            ->from($detailEditUrl)
            ->put(route('admin.image-libraries.update', ['libraryId' => $library->id]), [
                'name' => 'Updated from detail',
                'description' => 'Detail context remains local.',
                'context' => 'detail',
            ])
            ->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => $library->id]));

        $this->actingAs($admin, 'admin')
            ->put(route('admin.image-libraries.update', ['libraryId' => $library->id]), [
                'name' => 'Updated from index',
                'description' => 'Index context remains local.',
                'context' => 'index',
            ])
            ->assertRedirect(route('admin.image-libraries.index'));

        $this->actingAs($admin, 'admin')
            ->from(route('admin.image-libraries.edit', ['libraryId' => $library->id]))
            ->put(route('admin.image-libraries.update', ['libraryId' => $library->id]), [
                'name' => 'Unsafe context rejected',
                'description' => 'The redirect target must stay fixed.',
                'context' => 'https://attacker.example/redirect',
            ])
            ->assertSessionHasErrors('context')
            ->assertRedirect(route('admin.image-libraries.edit', ['libraryId' => $library->id]));

        $library->refresh();
        $this->assertSame('Updated from index', $library->name);
    }

    public function test_image_upload_server_error_is_uniquely_described_by_the_file_input(): void
    {
        $library = $this->imageLibrary();
        $errors = (new ViewErrorBag)->put(
            'default',
            new MessageBag(['images.0' => 'The selected image could not be uploaded.']),
        );

        $response = $this->actingAs($this->admin(), 'admin')
            ->withSession(['errors' => $errors])
            ->get(route('admin.image-libraries.images.create', ['libraryId' => $library->id]))
            ->assertOk()
            ->assertSee('aria-describedby="image-upload-help image-upload-status image-upload-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="image-upload-error"', false)
            ->assertSee('data-image-upload-dropzone', false)
            ->assertSee('border-red-300 bg-red-50', false)
            ->assertSeeText('The selected image could not be uploaded.');

        $this->assertSame(1, substr_count((string) $response->getContent(), 'id="image-upload-error"'));
    }

    public function test_image_upload_page_uses_the_backend_normalized_size_limit(): void
    {
        config()->set('geoflow.max_upload_bytes', 1025);
        $library = $this->imageLibrary();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.image-libraries.images.create', ['libraryId' => $library->id]))
            ->assertOk()
            ->assertSee('data-max-upload-bytes="2048"', false)
            ->assertSee('data-allowed-types="image/jpeg,image/png,image/gif,image/webp"', false)
            ->assertSee('data-allowed-extensions="jpg,jpeg,png,gif,webp"', false);
    }

    public function test_image_library_form_handles_array_old_input_without_a_server_error(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->withSession(['_old_input' => [
                'name' => ['unexpected'],
                'description' => ['unexpected'],
                'images' => ['unexpected'],
            ]])
            ->get(route('admin.image-libraries.create'))
            ->assertOk();
    }

    public function test_image_upload_post_endpoint_keeps_its_existing_storage_contract(): void
    {
        Storage::fake('public');
        $library = $this->imageLibrary();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.image-libraries.images.upload', ['libraryId' => $library->id]), [
                'images' => [UploadedFile::fake()->image('standalone-upload.png', 120, 80)],
            ])
            ->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => $library->id]));

        $image = Image::query()->where('library_id', $library->id)->firstOrFail();
        $this->assertSame('standalone-upload.png', $image->original_name);
        $this->assertStringStartsWith('storage/uploads/images/', (string) $image->file_path);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $image->managed_path_hash);
        Storage::disk('public')->assertExists(str_replace('storage/', '', (string) $image->file_path));
    }

    public function test_standalone_pages_keep_admin_authentication(): void
    {
        $library = $this->imageLibrary();
        $author = Author::query()->create(['name' => 'Protected author']);

        foreach ([
            route('admin.authors.create'),
            route('admin.authors.edit', ['authorId' => $author->id]),
            route('admin.image-libraries.create'),
            route('admin.image-libraries.edit', ['libraryId' => $library->id]),
            route('admin.image-libraries.images.create', ['libraryId' => $library->id]),
        ] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
    }

    public function test_dynamic_author_and_image_library_ids_are_limited_to_eighteen_digit_positive_integers(): void
    {
        foreach ([
            'admin.authors.edit' => 'authorId',
            'admin.authors.detail' => 'authorId',
            'admin.authors.update' => 'authorId',
            'admin.authors.delete' => 'authorId',
            'admin.image-libraries.edit' => 'libraryId',
            'admin.image-libraries.detail' => 'libraryId',
            'admin.image-libraries.images.create' => 'libraryId',
            'admin.image-libraries.images.upload' => 'libraryId',
            'admin.image-libraries.images.delete' => 'libraryId',
            'admin.image-libraries.detail.update' => 'libraryId',
            'admin.image-libraries.update' => 'libraryId',
            'admin.image-libraries.delete' => 'libraryId',
        ] as $routeName => $parameter) {
            $this->assertSame('[1-9][0-9]{0,17}', Route::getRoutes()->getByName($routeName)?->wheres[$parameter] ?? null, $routeName);
        }

        $this->actingAs($this->admin(), 'admin');

        foreach ([
            ['admin.authors.edit', 'authorId'],
            ['admin.image-libraries.edit', 'libraryId'],
            ['admin.image-libraries.detail', 'libraryId'],
            ['admin.image-libraries.images.create', 'libraryId'],
        ] as [$routeName, $parameter]) {
            foreach (['not-a-number', '0', '1234567890123456789'] as $invalidId) {
                $this->get(route($routeName, [$parameter => $invalidId], false))->assertNotFound();
            }
        }
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => uniqid('standalone_materials_', true),
            'password' => 'secret-123',
            'email' => uniqid('standalone-materials-', true).'@example.test',
            'display_name' => 'Standalone Materials Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function imageLibrary(): ImageLibrary
    {
        return ImageLibrary::query()->create([
            'name' => 'Standalone image library',
            'description' => 'Images for standalone upload flow.',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
    }
}
