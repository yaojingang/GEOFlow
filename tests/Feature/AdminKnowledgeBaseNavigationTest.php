<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminKnowledgeBaseNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_base_pages_share_the_same_context_navigation(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge-navigation',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => '导航测试知识库']);
        $session = [Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version];

        foreach ([
            ['active' => 'current', 'route' => 'admin.knowledge-bases.detail'],
            ['active' => 'current', 'route' => 'admin.knowledge-bases.edit'],
            ['active' => 'chunks', 'route' => 'admin.knowledge-bases.chunks.index'],
            ['active' => 'facts', 'route' => 'admin.knowledge-bases.facts.index'],
        ] as $page) {
            $active = $page['active'];
            $response = $this->withSession($session)
                ->actingAs($admin, 'admin')
                ->get(route($page['route'], ['knowledgeBaseId' => $knowledgeBase->id]))
                ->assertOk()
                ->assertSee(__('admin.knowledge_navigation.current'))
                ->assertSee(__('admin.knowledge_navigation.chunks'))
                ->assertSee(__('admin.knowledge_navigation.facts'))
                ->assertSee(route('admin.knowledge-bases.detail', $knowledgeBase->id, false), false)
                ->assertSee(route('admin.knowledge-bases.chunks.index', $knowledgeBase->id, false), false)
                ->assertSee(route('admin.knowledge-bases.facts.index', $knowledgeBase->id, false), false);

            $document = new DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new DOMXPath($document);
            $navigation = $xpath->query('//nav[@data-section-navigation="knowledge-base"]');
            $activeItems = $xpath->query('//nav[@data-section-navigation="knowledge-base"]//a[@aria-current="page"]');

            $this->assertSame(1, $navigation->length);
            $this->assertSame(1, $activeItems->length);
            $this->assertSame(
                'knowledge-'.$active,
                $activeItems->item(0)?->getAttribute('data-section-navigation-item'),
            );
            $this->assertStringContainsString(
                'px-4 sm:px-0',
                $navigation->item(0)?->parentNode?->parentNode?->attributes?->getNamedItem('class')?->nodeValue ?? '',
            );

            if ($page['route'] === 'admin.knowledge-bases.facts.index') {
                $this->assertSame(0, $xpath->query('//nav[@aria-label="面包屑"]')->length);
            }
        }
    }

    public function test_chunk_page_is_paginated_and_scoped_to_its_parent_knowledge_base(): void
    {
        $admin = Admin::query()->create([
            'username' => 'chunk-navigation',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => '目标知识库']);
        $otherBase = KnowledgeBase::query()->create(['name' => '其他知识库']);

        foreach (range(1, 31) as $index) {
            KnowledgeChunk::query()->create([
                'knowledge_base_id' => $knowledgeBase->id,
                'chunk_index' => $index,
                'content' => '目标切片 '.$index,
                'content_hash' => hash('sha256', 'target-'.$index),
                'source_hash' => hash('sha256', 'target-source'),
            ]);
        }
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $otherBase->id,
            'chunk_index' => 1,
            'content' => '其他知识库私有切片',
            'content_hash' => hash('sha256', 'other'),
            'source_hash' => hash('sha256', 'other-source'),
        ]);

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
            ->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.chunks.index', $knowledgeBase->id))
            ->assertOk()
            ->assertSee('目标切片 1')
            ->assertDontSee('其他知识库私有切片')
            ->assertSee('page=2', false);
    }

    public function test_chunk_page_requires_admin_authentication(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create(['name' => '受保护知识库']);

        $this->get(route('admin.knowledge-bases.chunks.index', $knowledgeBase->id))
            ->assertRedirect(route('admin.login'));
    }
}
