<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiWorkspace\RejectApprovalRequest;
use App\Http\Requests\Admin\AiWorkspace\SendMessageRequest;
use App\Http\Requests\Admin\AiWorkspace\StoreConversationRequest;
use App\Http\Requests\Admin\AiWorkspace\UpdatePlanRequest;
use App\Models\Admin;
use App\Models\AiWorkspaceApproval;
use App\Models\AiWorkspaceRun;
use App\Models\AiWorkspaceStep;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Services\AiWorkspace\AiWorkflowEngine;
use App\Services\AiWorkspace\AiWorkspaceCoordinator;
use App\Services\AiWorkspace\AiWorkspaceGovernanceMetrics;
use App\Services\AiWorkspace\AiWorkspaceSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Models\ConversationMessage;

final class AiWorkspaceApiController extends Controller
{
    public function __construct(
        private readonly AiConversationRepository $conversations,
        private readonly AiWorkspaceCoordinator $coordinator,
        private readonly AiWorkflowEngine $engine,
        private readonly AiWorkspaceSnapshot $snapshot,
        private readonly AiWorkspaceGovernanceMetrics $metrics,
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
        $messages = ConversationMessage::query()
            ->where('conversation_id', $model->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values()
            ->map(static fn (ConversationMessage $message): array => [
                'id' => (string) $message->id,
                'role' => (string) $message->role,
                'content' => (string) $message->content,
                'meta' => $message->meta ?? [],
                'created_at' => $message->created_at?->toISOString(),
            ])->all();
        $runs = AiWorkspaceRun::query()
            ->where('conversation_id', $model->id)
            ->where('admin_id', $this->admin($request)->id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (AiWorkspaceRun $run): array => $this->snapshot->make($run))
            ->all();

        return response()->json(['data' => [
            'id' => (string) $model->id,
            'title' => (string) $model->title,
            'messages' => $messages,
            'runs' => $runs,
        ]]);
    }

    public function archiveConversation(Request $request, string $conversation): JsonResponse
    {
        $model = $this->conversations->archive($this->admin($request), $conversation);
        $this->audit($request, 'conversation.archive', ['conversation_id' => $model->id]);

        return response()->json(['data' => ['id' => (string) $model->id, 'archived' => true]]);
    }

    public function sendMessage(SendMessageRequest $request, string $conversation): JsonResponse
    {
        $admin = $this->admin($request);
        $model = $this->conversations->findForAdmin($admin, $conversation);
        $run = $this->coordinator->createRun(
            $admin,
            $model,
            (string) $request->validated('prompt'),
            $request->validated('request_key'),
        );
        $this->audit($request, 'message.submit', ['conversation_id' => $model->id, 'run_id' => $run->id]);

        return response()->json(['data' => $this->snapshot->make($run->fresh())], 202);
    }

    public function showRun(Request $request, string $run): JsonResponse
    {
        $model = AiWorkspaceRun::query()->whereKey($run)->where('admin_id', $this->admin($request)->id)->firstOrFail();

        return response()->json(['data' => $this->snapshot->make($model)]);
    }

    public function metrics(Request $request): JsonResponse
    {
        abort_unless($this->admin($request)->isSuperAdmin(), 403);
        $days = min(90, max(1, (int) $request->integer('days', 7)));

        return response()->json(['data' => $this->metrics->snapshot($days)]);
    }

    public function approve(Request $request, string $approval): JsonResponse
    {
        $model = AiWorkspaceApproval::query()->findOrFail($approval);
        $run = $this->engine->approve($this->admin($request), $model);
        $this->audit($request, 'approval.approve', ['approval_id' => $approval, 'run_id' => $run->id]);

        return response()->json(['data' => $this->snapshot->make($run)]);
    }

    public function reject(RejectApprovalRequest $request, string $approval): JsonResponse
    {
        $model = AiWorkspaceApproval::query()->findOrFail($approval);
        $run = $this->engine->reject($this->admin($request), $model, $request->validated('reason'));
        $this->audit($request, 'approval.reject', ['approval_id' => $approval, 'run_id' => $run->id]);

        return response()->json(['data' => $this->snapshot->make($run)]);
    }

    public function updatePlan(UpdatePlanRequest $request, string $run): JsonResponse
    {
        $model = AiWorkspaceRun::query()->with('steps')->whereKey($run)->where('admin_id', $this->admin($request)->id)->firstOrFail();
        $updated = $this->engine->editPlan(
            $this->admin($request),
            $model,
            $request->validated('step_parameters'),
            (int) $request->validated('plan_version'),
        );
        $this->audit($request, 'plan.update', ['run_id' => $updated->id, 'plan_version' => $updated->plan_version]);

        return response()->json(['data' => $this->snapshot->make($updated)]);
    }

    public function cancel(Request $request, string $run): JsonResponse
    {
        $model = AiWorkspaceRun::query()->whereKey($run)->where('admin_id', $this->admin($request)->id)->firstOrFail();
        $cancelled = $this->engine->cancel($this->admin($request), $model);
        $this->audit($request, 'run.cancel', ['run_id' => $cancelled->id]);

        return response()->json(['data' => $this->snapshot->make($cancelled)]);
    }

    public function retryStep(Request $request, string $step): JsonResponse
    {
        $model = AiWorkspaceStep::query()->findOrFail($step);
        $run = $this->engine->retryStep($this->admin($request), $model);
        $this->audit($request, 'step.retry', ['run_id' => $run->id, 'step_id' => $step]);

        return response()->json(['data' => $this->snapshot->make($run)]);
    }

    private function admin(Request $request): Admin
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return $admin;
    }

    /** @param array<string,mixed> $details */
    private function audit(Request $request, string $action, array $details): void
    {
        $request->attributes->set('admin_activity_action', $action);
        $request->attributes->set('admin_activity_details', $details);
    }
}
