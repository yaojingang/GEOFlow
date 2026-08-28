<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiWorkspace\RenameConversationRequest;
use App\Http\Requests\Admin\AiWorkspace\SendMessageRequest;
use App\Http\Requests\Admin\AiWorkspace\StoreConversationRequest;
use App\Models\Admin;
use App\Models\AiConversationMessage;
use App\Services\AiWorkspace\AdminHelpAnswerStream;
use App\Services\AiWorkspace\AiConversationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AiWorkspaceApiController extends Controller
{
    public function __construct(
        private readonly AiConversationRepository $conversations,
        private readonly AdminHelpAnswerStream $answers,
    ) {}

    public function conversations(Request $request): JsonResponse
    {
        $items = $this->conversations->listForAdmin($this->admin($request))->map(static fn ($conversation): array => [
            'id' => (string) $conversation->id,
            'title' => (string) $conversation->title,
            'updated_at' => $conversation->updated_at?->toISOString(),
        ])->all();

        return response()->json(['data' => $items]);
    }

    public function storeConversation(StoreConversationRequest $request): JsonResponse
    {
        $conversation = $this->conversations->create($this->admin($request), $request->validated('title'));
        $this->audit($request, 'conversation.create', ['conversation_id' => $conversation->id]);

        return response()->json(['data' => [
            'id' => (string) $conversation->id,
            'title' => (string) $conversation->title,
            'updated_at' => $conversation->updated_at?->toISOString(),
        ]], 201);
    }

    public function showConversation(Request $request, string $conversation): JsonResponse
    {
        $model = $this->conversations->findForAdmin($this->admin($request), $conversation);
        $pageSize = 100;
        $messageQuery = AiConversationMessage::query()->where('conversation_id', $model->id);
        $before = trim((string) $request->query('before', ''));
        if ($before !== '') {
            abort_if(mb_strlen($before) > 36, 422, 'Invalid conversation history cursor.');
            $cursor = AiConversationMessage::query()
                ->where('conversation_id', $model->id)
                ->whereKey($before)
                ->firstOrFail();
            $messageQuery->where(function ($query) use ($cursor): void {
                $query->where('created_at', '<', $cursor->created_at)
                    ->orWhere(function ($query) use ($cursor): void {
                        $query->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id);
                    });
            });
        }

        $messagePage = $messageQuery
            ->select(['id', 'conversation_id', 'role', 'content', 'meta', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->limit($pageSize + 1)
            ->get();
        $hasMoreMessages = $messagePage->count() > $pageSize;
        $messagePage = $messagePage->take($pageSize);

        return response()->json(['data' => [
            'id' => (string) $model->id,
            'title' => (string) $model->title,
            'messages' => $messagePage->reverse()->values()->map(static fn (AiConversationMessage $message): array => [
                'id' => (string) $message->id,
                'role' => (string) $message->role,
                'content' => (string) $message->content,
                'meta' => $message->meta ?? [],
                'created_at' => $message->created_at?->toISOString(),
            ])->all(),
            'message_page' => [
                'has_more' => $hasMoreMessages,
                'next_cursor' => $hasMoreMessages ? (string) $messagePage->last()?->id : null,
            ],
        ]]);
    }

    public function archiveConversation(Request $request, string $conversation): JsonResponse
    {
        $model = $this->conversations->archive($this->admin($request), $conversation);
        $this->audit($request, 'conversation.archive', ['conversation_id' => $model->id]);

        return response()->json(['data' => ['id' => (string) $model->id, 'archived' => true]]);
    }

    public function renameConversation(RenameConversationRequest $request, string $conversation): JsonResponse
    {
        $model = $this->conversations->rename(
            $this->admin($request),
            $conversation,
            (string) $request->validated('title'),
        );
        $this->audit($request, 'conversation.rename', ['conversation_id' => $model->id]);

        return response()->json(['data' => [
            'id' => (string) $model->id,
            'title' => (string) $model->title,
            'updated_at' => $model->updated_at?->toISOString(),
        ]]);
    }

    public function sendMessage(SendMessageRequest $request, string $conversation): StreamedResponse
    {
        $admin = $this->admin($request);
        $model = $this->conversations->findForAdmin($admin, $conversation);
        $this->audit($request, 'message.ask', ['conversation_id' => $model->id]);

        return $this->answers->respond($admin, $model, (string) $request->validated('prompt'));
    }

    private function admin(Request $request): Admin
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return $admin;
    }

    /** @param array<string, mixed> $details */
    private function audit(Request $request, string $action, array $details): void
    {
        $request->attributes->set('admin_activity_action', $action);
        $request->attributes->set('admin_activity_details', $details);
    }
}
