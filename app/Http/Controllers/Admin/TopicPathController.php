<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Services\Topics\TopicPathService;
use App\Support\TopicAdminContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class TopicPathController extends Controller
{
    public function edit(Request $request, int $topic): View
    {
        return $this->page($this->topic($request, $topic));
    }

    public function preview(Request $request, int $topic, TopicPathService $paths): View
    {
        $model = $this->topic($request, $topic);
        $data = $request->validate(['path' => ['required', 'string', 'max:120']]);

        return $this->page($model, $paths->preview($model, $data['path'], TopicAdminContext::actor()->id));
    }

    public function confirm(Request $request, int $topic, TopicPathService $paths): RedirectResponse
    {
        $model = $this->topic($request, $topic);
        $data = $request->validate(['token' => ['required', 'string', 'max:5000']]);
        $paths->confirm($model, $data['token'], TopicAdminContext::actor()->id);

        return redirect()->route('admin.topics.edit', ['topic' => $topic])->with('message', '专题地址已更新。旧地址将直接跳转到新地址，维护任务已暂停，可在核对后恢复。');
    }

    private function topic(Request $request, int $id): Topic
    {
        $topic = Topic::query()->findOrFail($id);
        TopicAdminContext::site($request, $topic->site_key);
        Gate::forUser(TopicAdminContext::actor())->authorize('publish', $topic);

        return $topic;
    }

    private function page(Topic $topic, ?array $preview = null): View
    {
        return view('admin.topics.path', TopicAdminContext::viewData($topic->site_key) + compact('topic', 'preview'));
    }
}
