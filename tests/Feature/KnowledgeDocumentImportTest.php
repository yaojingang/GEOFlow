<?php

namespace Tests\Feature;

use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeBase;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KnowledgeDocumentFixtures;
use Tests\TestCase;

class KnowledgeDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string,string,string}> */
    public static function documentFormats(): array
    {
        return [
            'TXT' => ['txt', 'text', 'Plain text source evidence.'],
            'MD' => ['md', 'markdown', '# Markdown source evidence.'],
            'DOCX' => ['docx', 'word', 'Word source evidence & notes.'],
            'PDF' => ['pdf', 'pdf', 'PDF source evidence.'],
            'PPTX' => ['pptx', 'presentation', 'Presentation source evidence.'],
        ];
    }

    #[DataProvider('documentFormats')]
    public function test_knowledge_base_import_saves_extracted_text_and_original_file_type(string $extension, string $type, string $body): void
    {
        Storage::fake('local');
        $file = $this->document($extension, $body);

        $response = $this->actingAs($this->admin(), 'admin')->post(route('admin.knowledge-bases.store'), [
            'name' => 'Imported document',
            'knowledge_files' => [$file],
            'import_action' => 'save',
        ]);

        $response->assertRedirect(route('admin.knowledge-bases.index'))->assertSessionHasNoErrors();
        $base = KnowledgeBase::query()->where('name', 'Imported document')->firstOrFail();
        $this->assertSame($type, $base->file_type);
        $this->assertStringContainsString('# 文件：source.'.$extension, $base->content);
        $this->assertStringContainsString($body, $base->content);
        Storage::disk('local')->assertExists($base->file_path);
        $this->assertSame(file_get_contents($file->getPathname()), Storage::disk('local')->get($base->file_path));
    }

    #[DataProvider('documentFormats')]
    public function test_enterprise_knowledge_import_saves_document_sources_and_queues_generation(string $extension, string $type, string $body): void
    {
        Storage::fake('local');
        Queue::fake([GenerateEnterpriseKnowledgeDraftJob::class]);
        $admin = $this->admin();
        $this->executionModel($admin);
        $file = $this->document($extension, $body);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.enterprise-knowledge.store'), [
            'name' => 'Imported enterprise document',
            'enterprise_files' => [$file],
        ]);

        $response->assertSessionHasNoErrors();
        $project = EnterpriseKnowledgeProject::query()->where('name', 'Imported enterprise document')->firstOrFail();
        $response->assertRedirect(route('admin.enterprise-knowledge.show', $project->id));
        $source = $project->sources()->sole();
        $this->assertSame($type, $source->file_type);
        $this->assertSame('source.'.$extension, $source->original_name);
        $this->assertStringContainsString($body, $source->content);
        Storage::disk('local')->assertExists($source->file_path);
        $this->assertSame(file_get_contents($file->getPathname()), Storage::disk('local')->get($source->file_path));
        Queue::assertPushed(GenerateEnterpriseKnowledgeDraftJob::class, fn (GenerateEnterpriseKnowledgeDraftJob $job): bool => $job->projectId === $project->id);
    }

    public function test_legacy_single_file_field_uses_the_shared_pdf_parser(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin(), 'admin')->post(route('admin.knowledge-bases.store'), [
            'knowledge_file' => $this->document('pdf', 'Legacy field PDF body.'),
            'import_action' => 'save',
        ])->assertSessionHasNoErrors();

        $base = KnowledgeBase::query()->where('name', 'source')->sole();
        $this->assertSame('pdf', $base->file_type);
        $this->assertStringContainsString('Legacy field PDF body.', $base->content);
        Storage::disk('local')->assertExists($base->file_path);
    }

    public function test_combined_knowledge_sources_keep_markdown_merge_semantics(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin(), 'admin')->post(route('admin.knowledge-bases.store'), [
            'name' => 'Combined sources',
            'content' => 'Manual context.',
            'knowledge_files' => [$this->document('pdf', 'PDF evidence.'), $this->document('pptx', 'Slide evidence.')],
            'import_action' => 'save',
        ])->assertSessionHasNoErrors();

        $base = KnowledgeBase::query()->where('name', 'Combined sources')->sole();
        $this->assertSame('markdown', $base->file_type);
        $this->assertSame("# 手动输入内容\n\nManual context.\n\n---\n\n# 文件：source.pdf\n\nPDF evidence.\n\n---\n\n# 文件：source.pptx\n\n# Slide 1\n\nSlide evidence.", $base->content);
        Storage::disk('local')->assertExists(json_decode($base->file_path, true));
    }

    public static function rejectedDocuments(): array
    {
        $blank = KnowledgeDocumentFixtures::pptxEntries([1 => '']);
        $imageOnly = $blank;
        $imageOnly['ppt/slides/slide1.xml'] = str_replace('<a:r><a:t></a:t></a:r>', '<p:pic/>', $blank['ppt/slides/slide1.xml']);
        $unsafe = KnowledgeDocumentFixtures::pptxEntries([1 => 'Valid body']);
        $unsafe['ppt/_rels/presentation.xml.rels'] = str_replace('Target="slides/slide1.xml"', 'TargetMode="External" Target="https://example.com/slide.xml"', $unsafe['ppt/_rels/presentation.xml.rels']);
        $compressed = KnowledgeDocumentFixtures::pptxEntries([1 => str_repeat('A', 2 * 1024 * 1024)]);
        $documents = [
            'blank PDF' => ['blank.pdf', KnowledgeDocumentFixtures::pdf(''), 'pdf_no_text'],
            'invalid PDF' => ['broken.pdf', "%PDF-1.4\nbroken\n%%EOF", 'pdf_invalid'],
            'blank PPTX' => ['blank.pptx', KnowledgeDocumentFixtures::zip($blank), 'pptx_no_text'],
            'image-only PPTX' => ['images.pptx', KnowledgeDocumentFixtures::zip($imageOnly), 'pptx_no_text'],
            'external PPTX relationship' => ['unsafe.pptx', KnowledgeDocumentFixtures::zip($unsafe), 'pptx_invalid'],
            'compressed PPTX' => ['bomb.pptx', KnowledgeDocumentFixtures::zip($compressed), 'pptx_expansion_too_large'],
            'legacy PPT extension' => ['legacy.ppt', KnowledgeDocumentFixtures::pptx([1 => 'Renamed modern file']), 'file_type_invalid'],
            'legacy DOC extension' => ['legacy.doc', 'A renamed text document.', 'file_type_invalid'],
        ];
        $cases = [];
        foreach (['knowledge', 'enterprise'] as $entry) {
            foreach ($documents as $name => $document) {
                $cases[$entry.' / '.$name] = [$entry, ...$document];
            }
        }

        return $cases;
    }

    #[DataProvider('rejectedDocuments')]
    public function test_rejected_imports_leave_no_records_files_or_jobs(string $entry, string $filename, string $bytes, string $message): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->admin();
        $route = $entry === 'enterprise' ? 'admin.enterprise-knowledge.store' : 'admin.knowledge-bases.store';
        $field = $entry === 'enterprise' ? 'enterprise_files' : 'knowledge_files';
        if ($entry === 'enterprise') {
            $this->executionModel($admin);
        }
        $baseCount = KnowledgeBase::query()->count();
        $projectCount = EnterpriseKnowledgeProject::query()->count();

        $response = $this->actingAs($admin, 'admin')->post(route($route), [
            'name' => 'Rejected import',
            $field => [
                UploadedFile::fake()->createWithContent('first.txt', 'This earlier source must be cleaned up.'),
                UploadedFile::fake()->createWithContent($filename, $bytes),
            ],
            'import_action' => 'save',
        ]);

        $response->assertSessionHasErrors();
        $this->assertStringContainsString(__('admin.knowledge_bases.error.'.$message), implode(' ', session('errors')->all()));
        $this->assertDatabaseCount('knowledge_bases', $baseCount);
        $this->assertDatabaseCount('enterprise_knowledge_projects', $projectCount);
        $this->assertDatabaseCount('enterprise_knowledge_sources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
    }

    private function document(string $extension, string $body): UploadedFile
    {
        $bytes = match ($extension) {
            'pdf' => KnowledgeDocumentFixtures::pdf($body, true),
            'pptx' => KnowledgeDocumentFixtures::pptx([1 => $body]),
            'docx' => KnowledgeDocumentFixtures::docx($body),
            default => $body,
        };

        return UploadedFile::fake()->createWithContent('source.'.$extension, $bytes);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'document-import-admin',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function executionModel(Admin $admin): void
    {
        $model = AiModel::query()->create([
            'name' => 'Document import execution model',
            'model_type' => 'chat',
            'version' => 'v1',
            'model_id' => 'import-test',
            'api_url' => 'https://ai.test/v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'status' => 'active',
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'failover_priority' => 10,
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
    }
}
