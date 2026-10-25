<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\Task;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final readonly class TaskCreationFlow
{
    public function __construct(private TaskCreationCatalog $catalog, private TaskLifecycleService $tasks) {}

    public function handles(AiConversation $conversation, string $prompt, array $input = []): bool
    {
        if ($this->isLocalControlRequest($prompt, $input) || in_array($conversation->task_draft['status'] ?? '', ['collecting', 'ready'], true)) {
            return true;
        }
        if (($conversation->task_draft['status'] ?? '') === 'created' && $this->isConfirmation($prompt)) {
            return true;
        }
        if (preg_match('/^(如何|怎么|怎样|how\b)/iu', trim($prompt))) {
            return false;
        }

        return $this->requestedContentType($prompt) === 'topic' || (bool) preg_match('/(?:创建|增加|新建|添加|安排|建一个|建个|create|add|set up).{0,45}(?:任务|task)|(?:任务|task).{0,25}(?:创建|增加|新建|添加)/iu', $prompt);
    }

    public function isLocalControlRequest(string $prompt, array $validated): bool
    {
        return ! empty($validated['task_draft_id']) && (int) ($validated['task_draft_revision'] ?? 0) > 0
            && (isset($validated['task_choice']['field'], $validated['task_choice']['id'])
                || $this->isConfirmation($prompt) || $this->isCancellation($prompt));
    }

    public function isConfirmation(string $prompt): bool
    {
        $text = mb_strtolower(trim($prompt));
        $text = preg_replace('/[\s。！!，,.]+$/u', '', $text);

        return in_array($text, ['按这个创建', '按这些设置创建', '确认创建', '创建任务', '就这样创建', 'create task', 'confirm creation'], true);
    }

    public function isCancellation(string $prompt): bool
    {
        return in_array(mb_strtolower(trim($prompt)), ['取消创建', '取消这个任务', '不创建了', 'cancel task creation'], true);
    }

    public function emptyDraft(): array
    {
        return [
            'id' => (string) Str::uuid7(), 'revision' => 0, 'status' => 'collecting',
            'data' => [
                'content_type' => 'article', 'site_key' => 'primary', 'topic_limit' => null,
                'after' => 'auto_publish', 'template_key' => 'default', 'category_ids' => [], 'rules' => null,
                'name' => null, 'article_limit' => null, 'title_library_id' => null,
                'prompt_id' => null, 'ai_model_id' => null, 'fixed_category_id' => null,
                'knowledge_base_ids' => [], 'author_id' => null, 'image_library_id' => null,
                'image_count' => 0, 'publish_interval_minutes' => 60,
                'need_review' => 1,
            ],
        ];
    }

    public function collect(array $previous, array $response, array $catalog, ?string $selectedField = null): array
    {
        $previous['data'] = [...$this->emptyDraft()['data'], ...$previous['data']];
        foreach (['content_type', 'site_key', 'topic_limit', 'after', 'template_key', 'category_ids', 'rules'] as $field) {
            if (is_array($response['draft'] ?? null) && ! array_key_exists($field, $response['draft'])) {
                $response['draft'][$field] = $previous['data'][$field];
            }
        }
        if (is_array($response['draft'] ?? null) && ! array_key_exists('need_review', $response['draft'])) {
            $response['draft']['need_review'] = $previous['data']['need_review'];
        }
        $topic = ($response['draft']['content_type'] ?? 'article') === 'topic';
        $rules = [
            'intent' => ['required', 'in:collect,cancel,unsupported'],
            'reply' => ['required', 'string', 'max:1000'],
            'draft' => ['required', 'array'],
            'draft.content_type' => ['required', 'in:article,topic'],
            'draft.name' => ['present', 'nullable', 'string', 'max:200'],
            'draft.article_limit' => ['present', 'nullable', 'integer', 'min:1', 'max:99999'],
            'draft.title_library_id' => ['present', 'nullable', 'integer', 'min:1'],
            'draft.prompt_id' => ['present', 'nullable', 'integer', 'min:1'],
            'draft.ai_model_id' => ['present', 'nullable', 'integer', 'min:1'],
            'draft.fixed_category_id' => ['present', 'nullable', 'integer', 'min:1'],
            'draft.knowledge_base_ids' => ['present', 'array', 'max:5'],
            'draft.knowledge_base_ids.*' => ['integer', 'min:1', 'distinct'],
            'draft.author_id' => ['present', 'nullable', 'integer', 'min:1'],
            'draft.image_library_id' => ['present', 'nullable', 'integer', 'min:1'],
            'draft.image_count' => ['required', 'integer', 'min:0', 'max:5'],
            'draft.publish_interval_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'draft.need_review' => ['required', 'integer', 'in:0,1'],
        ];
        if ($topic) {
            $rules = array_intersect_key($rules, array_flip(['intent', 'reply', 'draft', 'draft.content_type', 'draft.name', 'draft.title_library_id', 'draft.ai_model_id', 'draft.publish_interval_minutes']));
            $rules += [
                'draft.site_key' => ['required', 'string', 'max:80'],
                'draft.topic_limit' => ['present', 'nullable', 'integer', 'min:1', 'max:99999'],
                'draft.after' => ['required', 'in:auto_publish,draft_only,review_then_publish'],
                'draft.template_key' => ['required', 'in:default,guide,roundup'],
                'draft.category_ids' => ['present', 'array', 'max:100'],
                'draft.category_ids.*' => ['integer', 'min:1', 'distinct'],
                'draft.rules' => ['present', 'nullable', 'string', 'max:5000'],
            ];
        }
        $fields = array_values(array_unique(array_map(fn (string $key): string => explode('.', $key)[1] ?? '', array_keys($rules))));
        $validator = Validator::make($response, $rules);
        $issues = $selectedField === null ? [] : array_diff_key($previous['issues'] ?? [], [$selectedField => true]);
        $issues = array_intersect_key($issues, array_flip($fields));
        if ($validator->fails()) {
            foreach ($validator->errors()->keys() as $key) {
                $field = explode('.', $key)[1] ?? '';
                if (! str_starts_with($key, 'draft.') || ! array_key_exists($field, $previous['data'])) {
                    $validator->validate();
                }
                $response['draft'][$field] = $previous['data'][$field];
                $issues[$field] = __('ai-task.invalid_value', ['field' => __('ai-task.fields.'.$field)]);
                if (in_array($field, ['article_limit', 'topic_limit', 'image_count', 'publish_interval_minutes', 'knowledge_base_ids', 'category_ids', 'rules'], true)) {
                    $issues[$field] = __('ai-task.limits.'.$field);
                }
            }
        }
        $validated = Validator::make($response, $rules)->validate();
        $draft = $previous;
        $draft['issues'] = $issues;
        $draft['revision']++;
        if ($validated['intent'] === 'cancel') {
            $draft['status'] = 'cancelled';
            $draft['review_hash'] = null;

            return [$draft, __('ai-task.cancelled')];
        }
        if ($validated['intent'] === 'unsupported') {
            $draft['issues'] = $previous['issues'] ?? [];
            $problems = $this->problems($draft, $catalog);
            $draft['status'] = $problems === [] ? 'ready' : 'collecting';
            $draft['review_hash'] = $problems === [] ? $this->reviewHash($draft, $catalog) : null;

            return [$draft, __('ai-task.scope')];
        }
        $data = [...$previous['data'], ...array_intersect_key($validated['draft'], array_flip($fields))];
        foreach (['article_limit', 'topic_limit', ...array_keys(TaskCreationCatalog::REFERENCES), 'image_count', 'publish_interval_minutes', 'need_review'] as $key) {
            if ($data[$key] !== null) {
                $data[$key] = (int) $data[$key];
            }
        }
        $data['name'] = trim((string) $data['name']) ?: null;
        $data['knowledge_base_ids'] = array_map('intval', $data['knowledge_base_ids']);
        $data['category_ids'] = array_map('intval', $data['category_ids']);
        $draft['data'] = $data;
        $problems = $this->problems($draft, $catalog);
        $draft['status'] = $problems === [] ? 'ready' : 'collecting';
        $draft['review_hash'] = $problems === [] ? $this->reviewHash($draft, $catalog) : null;

        $reply = $problems === [] ? __('ai-task.ready') : $validated['reply'];
        if (! $topic && $data['need_review'] !== (int) $previous['data']['need_review']) {
            $reply = __('ai-task.review_changed', ['review' => $this->reviewLabel($data)]).' '.$reply;
        }

        return [$draft, $reply];
    }

    public function problems(array $draft, array $catalog): array
    {
        $data = [...$this->emptyDraft()['data'], ...$draft['data']];
        if ($data['content_type'] === 'topic') {
            return $this->topicProblems($draft, $catalog);
        }
        $problems = [];
        foreach (['name', 'article_limit', 'title_library_id', 'prompt_id', 'ai_model_id', 'fixed_category_id'] as $field) {
            if (empty($data[$field])) {
                $problems[$field] = __('ai-task.questions.'.$field);
            }
        }
        foreach (TaskCreationCatalog::REFERENCES as $field => $group) {
            if (! empty($data[$field]) && $this->catalog->label($catalog, $group, (int) $data[$field]) === null) {
                $problems[$field] = __('ai-task.invalid_option', ['field' => __('ai-task.fields.'.$field)]);
            }
            if (in_array($field, ['title_library_id', 'prompt_id', 'ai_model_id', 'fixed_category_id'], true) && ($catalog[$group] ?? []) === []) {
                $problems[$field] = __('ai-task.missing_config', ['field' => __('ai-task.fields.'.$field)]);
            }
        }
        foreach ($data['knowledge_base_ids'] as $id) {
            if ($this->catalog->label($catalog, 'knowledge_bases', (int) $id) === null) {
                $problems['knowledge_base_ids'] = __('ai-task.invalid_option', ['field' => __('ai-task.fields.knowledge_base_ids')]);
            }
        }
        if (empty($data['title_library_id']) && ! empty($data['article_limit'])
            && $catalog['title_libraries'] !== [] && ! collect($catalog['title_libraries'])->contains(fn ($row) => (int) $row['available'] >= $data['article_limit'])) {
            $problems['title_library_id'] = __('ai-task.no_ready_titles', ['count' => $data['article_limit']]);
        }
        $library = collect($catalog['title_libraries'])->firstWhere('id', $data['title_library_id']);
        if ($library && ! empty($data['article_limit']) && (int) $library['available'] < $data['article_limit']) {
            $problems['article_limit'] = __('ai-task.insufficient_titles', ['count' => (int) $library['available']]);
        }
        if ($data['image_count'] > 0) {
            $images = collect($catalog['image_libraries'])->firstWhere('id', $data['image_library_id']);
            if (! $images || (int) $images['images_count'] < $data['image_count']) {
                $problems['image_library_id'] = __('ai-task.insufficient_images');
            }
        } elseif ($data['image_library_id'] !== null) {
            $problems['image_count'] = __('ai-task.image_count_required');
        }

        return [...$problems, ...($draft['issues'] ?? [])];
    }

    public function reviewHash(array $draft, array $catalog): string
    {
        return hash('sha256', json_encode([$draft['data'], $this->rows($draft, $catalog)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function confirm(Admin $admin, array $draft, array $input, array $catalog): array
    {
        if (($input['task_draft_id'] ?? '') !== $draft['id']) {
            return [$draft, __('ai-task.stale')];
        }
        if ($draft['status'] === 'created') {
            return [$draft, __('ai-task.created')];
        }
        if ((int) ($input['task_draft_revision'] ?? 0) !== $draft['revision'] || $draft['status'] !== 'ready') {
            return [$draft, __('ai-task.stale')];
        }
        $problems = $this->problems($draft, $catalog);
        if ($problems !== [] || ! hash_equals((string) $draft['review_hash'], $this->reviewHash($draft, $catalog))) {
            $draft['revision']++;
            $draft['status'] = $problems === [] ? 'ready' : 'collecting';
            $draft['review_hash'] = $problems === [] ? $this->reviewHash($draft, $catalog) : null;

            return [$draft, __('ai-task.changed')];
        }
        $data = [...$this->emptyDraft()['data'], ...$draft['data']];
        $payload = $data['content_type'] === 'topic' ? [
            'content_type' => 'topic', 'name' => $data['name'], 'target_site_key' => $data['site_key'],
            'topic_limit' => $data['topic_limit'], 'title_library_id' => $data['title_library_id'],
            'ai_model_id' => $data['ai_model_id'], 'publish_interval' => $data['publish_interval_minutes'] * 60,
            'status' => 'paused',
            'topic_settings' => [
                'after' => $data['after'], 'template_key' => $data['template_key'],
                'category_ids' => $data['category_ids'], 'rules' => $data['rules'] ?? '',
            ],
        ] : [
            ...$data,
            'publish_interval' => $data['publish_interval_minutes'] * 60,
            'status' => 'paused', 'publish_scope' => 'local_only', 'need_review' => $data['need_review'] ?? 1,
            'is_loop' => 0, 'category_mode' => 'fixed', 'model_selection_mode' => 'fixed',
            'draft_limit' => min(10, $data['article_limit']),
            'auto_keywords' => 1, 'auto_description' => 1, 'ai_quality_enabled' => false,
        ];
        $task = $this->tasks->createTask($payload, (int) $admin->getKey(), null, $admin);
        $draft['status'] = 'created';
        $draft['task_id'] = (int) $task['id'];
        $draft['revision']++;

        return [$draft, __('ai-task.created')];
    }

    public function card(array $draft, array $catalog): array
    {
        $draft['data'] = [...$this->emptyDraft()['data'], ...$draft['data']];
        $topic = $draft['data']['content_type'] === 'topic';
        $problems = in_array($draft['status'], ['created', 'cancelled'], true) ? [] : $this->problems($draft, $catalog);
        $questions = [];
        foreach (array_slice($problems, 0, 2, true) as $field => $question) {
            $group = ['site_key' => 'sites', 'after' => 'topic_generation_modes', 'template_key' => 'topic_templates'][$field] ?? TaskCreationCatalog::REFERENCES[$field] ?? ($field === 'knowledge_base_ids' ? 'knowledge_bases' : null);
            $options = [];
            $availableOptions = $catalog[$group] ?? [];
            if ($group === 'title_libraries' && ! $topic) {
                $availableOptions = array_values(array_filter($availableOptions, fn ($row) => (int) $row['available'] >= max(1, (int) $draft['data']['article_limit'])));
            }
            foreach (array_slice($availableOptions, 0, 6) as $option) {
                $prompt = __('ai-task.select_option', ['field' => __('ai-task.fields.'.$field), 'name' => $option['name'], 'id' => $option['id']]);
                $label = $option['name'].(count(array_filter($catalog[$group], fn ($row) => $row['name'] === $option['name'])) > 1 ? ' · #'.$option['id'] : '');
                if ($group === 'title_libraries') {
                    $label .= ' · '.__($topic ? 'ai-task.total_titles' : 'ai-task.available_titles', ['count' => $option[$topic ? 'titles_count' : 'available']]);
                }
                $optionData = ['label' => $label, 'prompt' => $prompt];
                if (isset(TaskCreationCatalog::REFERENCES[$field]) || $field === 'knowledge_base_ids') {
                    $optionData['choice'] = ['field' => $field, 'id' => (int) $option['id']];
                }
                $options[] = $optionData;
            }
            if ($field === 'name' && ($libraryName = $this->catalog->label($catalog, 'title_libraries', (int) ($draft['data']['title_library_id'] ?? 0)))) {
                $suggestedName = __($topic ? 'ai-task.suggested_topic_name' : 'ai-task.suggested_name', ['name' => mb_substr(preg_replace('/\s*标题库$/u', '', $libraryName), 0, 150)]);
                $options[] = ['label' => __('ai-task.use_name', ['name' => $suggestedName]), 'prompt' => __('ai-task.name_prompt', ['name' => $suggestedName])];
            }
            $questions[] = ['label' => $question, 'options' => $options];
        }
        $links = [];
        if ($draft['status'] === 'created' && Task::query()->whereKey($draft['task_id'])->exists()) {
            $links[] = ['label' => __('ai-task.view_task'), 'url' => route('admin.tasks.index', [], false)];
            $links[] = ['label' => __('ai-task.edit_task'), 'url' => route('admin.tasks.edit', ['taskId' => $draft['task_id']], false)];
            if ($topic) {
                $links[] = ['label' => __('ai-task.view_topics'), 'url' => route('admin.topics.index', ['site' => $draft['data']['site_key'], 'task_id' => $draft['task_id']], false)];
            }
        } elseif ($problems !== []) {
            $links[] = ['label' => __('ai-task.open_form'), 'url' => route('admin.tasks.create', $topic ? ['content_type' => 'topic', 'site' => $draft['data']['site_key']] : [], false)];
        }

        return [
            'id' => $draft['id'], 'revision' => $draft['revision'], 'status' => $problems !== [] && $draft['status'] === 'ready' ? 'collecting' : $draft['status'],
            'title' => __('ai-task.title'), 'rows' => $this->rows($draft, $catalog),
            'remaining_fields' => array_keys($problems),
            'guidance' => $problems !== [] ? __('ai-task.remaining', ['count' => count($problems), 'fields' => implode(app()->getLocale() === 'zh_CN' ? '、' : ', ', array_map(fn ($field) => __('ai-task.fields.'.$field), array_keys($problems)))]) : '',
            'questions' => $questions, 'links' => $links,
            'task_id' => $draft['task_id'] ?? null,
        ];
    }

    private function rows(array $draft, array $catalog): array
    {
        $data = [...$this->emptyDraft()['data'], ...$draft['data']];
        if ($data['content_type'] === 'topic') {
            return $this->topicRows($data, $catalog);
        }
        $rows = [];
        foreach (['name', 'article_limit', ...array_keys(TaskCreationCatalog::REFERENCES)] as $field) {
            $value = $data[$field] ?? null;
            if ($value === null) {
                continue;
            }
            $group = TaskCreationCatalog::REFERENCES[$field] ?? null;
            $display = $group ? $this->catalog->label($catalog, $group, (int) $value) : (string) $value;
            $rows[] = ['label' => __('ai-task.fields.'.$field), 'value' => $display ?? __('ai-task.unavailable')];
        }
        $knowledge = array_map(fn ($id) => $this->catalog->label($catalog, 'knowledge_bases', (int) $id) ?? __('ai-task.unavailable'), $data['knowledge_base_ids']);
        $rows[] = ['label' => __('ai-task.fields.knowledge_base_ids'), 'value' => $knowledge ? implode('、', $knowledge) : __('ai-task.none')];
        $rows[] = ['label' => __('ai-task.fields.image_count'), 'value' => (string) $data['image_count']];
        $rows[] = ['label' => __('ai-task.fields.publish_interval_minutes'), 'value' => __('ai-task.minutes', ['count' => $data['publish_interval_minutes']])];
        $rows[] = ['label' => __('ai-task.delivery'), 'value' => __('ai-task.delivery_value', ['review' => $this->reviewLabel($data)])];
        $rows[] = ['label' => __('ai-task.after_create'), 'value' => __('ai-task.paused')];

        return $rows;
    }

    public function requestedContentType(string $prompt): ?string
    {
        // Preserve a quoted value belonging to an explicit task-type command.
        $prompt = preg_replace('~((?:任务(?:的)?(?:内容)?(?:类型)?\s*(?:改成|改为|设为|设置为|切换为|切换到|[:：=])|task(?:\s+content)?(?:\s+type)?\s+to)\s*)[“「\x{22}\x{27}](专题|文章|topic|article)[”」\x{22}\x{27}]~iu', '$1$2', $prompt);
        $prompt = preg_replace('~“[^”]*”|「[^」]*」|\x{22}[^\x{22}]*\x{22}|\x{27}[^\x{27}]*\x{27}~u', '', $prompt);
        $prefix = '(?:^|[，。；;.!?\n])\s*(?:(?:请(?:你)?|帮我|为我|我想(?:要)?|我要|我希望|想要|麻烦(?:你)?|please)\s*)*';
        $descriptors = '(?:名为|名称(?:为|是)|叫做|主题(?:为|是)|规则(?:为|是)|标题(?:为|是)|named\b|called\b|titled\b|about\b|with\s+(?:the\s+)?(?:name|title|theme|rules)\b|whose\b|并|然后|接着|\band\b)';
        $patterns = [
            '~'.$prefix.'(?:创建|增加|新建|添加|安排|建立|建)\s*(?:一个|一项|\d+\s*个?)?\s*(?:名为|名称(?:为|是)|叫做|主题(?:为|是)|规则(?:为|是)|标题(?:为|是))[^，。；;.!?:：\n并]{0,60}?的\s*(?<type>专题|文章)(?:发布|生成)?任务(?=$|[，。；;.!?\s]|并|然后|接着)~iu',
            '~'.$prefix.'(?:创建|增加|新建|添加|安排|建立|建|create\b|add\b|set\s+up\b)(?:(?!'.$descriptors.')[^，。；;.!?:：\n\x{22}\x{27}“”]){0,60}?(?<type>专题|文章|topic\b|article\b)\s*(?:(?:发布|生成|publishing|generation)\s*)?(?:任务|task\b)~iu',
            '~'.$prefix.'(?:创建|增加|新建|添加|安排|建立|建)\s*(?:一个|一项|\d+\s*个?)?\s*(?<type>专题)(?=$|[，。；;.!?\s])~iu',
            '~'.$prefix.'(?:把|将)?\s*(?:这个|当前|该|此)?\s*(?:文章|专题)?任务(?:的)?(?:内容)?(?:类型)?\s*(?:改成|改为|设为|设置为|切换为|切换到|[:：=])\s*(?<type>专题|文章)(?:发布|生成)?(?:任务)?(?=$|[，。；;.!?\s])~iu',
            '~'.$prefix.'(?:改成|改为|切换为|切换到)\s*(?<type>专题|文章)(?:发布|生成)?任务(?=$|[，。；;.!?\s])~iu',
            '~'.$prefix.'(?:change|switch|set)\s+(?:the\s+)?(?:(?:article|topic)\s+)?task(?:\s+content)?(?:\s+type)?\s+to\s+(?<type>topic|article)\b(?:\s+task)?~iu',
            '~'.$prefix.'(?:change|switch)\s+to\s+(?:an?\s+)?(?<type>topic|article)\s+task\b~iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches)) {
                return in_array(mb_strtolower($matches['type']), ['专题', 'topic'], true) ? 'topic' : 'article';
            }
        }

        return null;
    }

    public function supportsChoice(array $draft, string $field): bool
    {
        return ($draft['data']['content_type'] ?? 'article') === 'topic'
            ? in_array($field, ['title_library_id', 'ai_model_id'], true)
            : isset(TaskCreationCatalog::REFERENCES[$field]) || $field === 'knowledge_base_ids';
    }

    private function topicProblems(array $draft, array $catalog): array
    {
        $data = [...$this->emptyDraft()['data'], ...$draft['data']];
        $problems = [];
        foreach (['name', 'topic_limit'] as $field) {
            if (empty($data[$field])) {
                $problems[$field] = __('ai-task.questions.'.$field);
            }
        }
        if ($this->catalog->label($catalog, 'sites', $data['site_key']) === null) {
            $problems['site_key'] = __('ai-task.invalid_option', ['field' => __('ai-task.fields.site_key')]);
        }
        foreach (['title_library_id', 'ai_model_id'] as $field) {
            if ($data[$field] !== null && $this->catalog->label($catalog, TaskCreationCatalog::REFERENCES[$field], (int) $data[$field]) === null) {
                $problems[$field] = __('ai-task.invalid_option', ['field' => __('ai-task.fields.'.$field)]);
            }
        }
        foreach ($data['category_ids'] as $id) {
            if ($this->catalog->label($catalog, 'categories', (int) $id) === null) {
                $problems['category_ids'] = __('ai-task.invalid_option', ['field' => __('ai-task.fields.category_ids')]);
            }
        }

        return [...$problems, ...($draft['issues'] ?? [])];
    }

    private function topicRows(array $data, array $catalog): array
    {
        $rows = [];
        foreach (['content_type' => 'content_types', 'site_key' => 'sites', 'name' => null, 'topic_limit' => null,
            'title_library_id' => 'title_libraries', 'ai_model_id' => 'models', 'after' => 'topic_generation_modes', 'template_key' => 'topic_templates'] as $field => $group) {
            $value = $data[$field];
            $display = $value === null ? __('ai-task.none') : ($group ? $this->catalog->label($catalog, $group, $value) : (string) $value);
            $rows[] = ['label' => __('ai-task.fields.'.$field), 'value' => $display ?? __('ai-task.unavailable')];
        }
        $categories = array_map(fn ($id) => $this->catalog->label($catalog, 'categories', $id) ?? __('ai-task.unavailable'), $data['category_ids']);
        $rows[] = ['label' => __('ai-task.fields.category_ids'), 'value' => $categories ? implode('、', $categories) : __('ai-task.all_categories')];
        $rows[] = ['label' => __('ai-task.fields.rules'), 'value' => $data['rules'] ?: __('ai-task.none')];
        $rows[] = ['label' => __('ai-task.fields.publish_interval_minutes'), 'value' => __('ai-task.minutes', ['count' => $data['publish_interval_minutes']])];
        $rows[] = ['label' => __('ai-task.after_create'), 'value' => __('ai-task.paused')];

        return $rows;
    }

    private function reviewLabel(array $data): string
    {
        return (int) ($data['need_review'] ?? 1) === 0 ? __('ai-task.review_automatic') : __('ai-task.review_manual');
    }
}
