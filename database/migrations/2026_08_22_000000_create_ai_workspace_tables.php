<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conversationTable = (string) config('ai.conversations.tables.conversations', 'agent_conversations');
        $messageTable = (string) config('ai.conversations.tables.messages', 'agent_conversation_messages');

        if (Schema::hasTable('ai_models')) {
            Schema::table('ai_models', function (Blueprint $table): void {
                if (! Schema::hasColumn('ai_models', 'ai_workspace_structured_output_status')) {
                    $table->string('ai_workspace_structured_output_status', 30)->nullable();
                }
                if (! Schema::hasColumn('ai_models', 'ai_workspace_structured_output_verified_at')) {
                    $table->timestamp('ai_workspace_structured_output_verified_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable($conversationTable)) {
            Schema::create($conversationTable, function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('participant_type')->nullable();
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->string('title');
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->index(['participant_type', 'participant_id', 'updated_at'], 'agent_conversations_participant_updated_idx');
            });
        }

        if (! Schema::hasTable($messageTable)) {
            Schema::create($messageTable, function (Blueprint $table) use ($conversationTable): void {
                $table->string('id', 36)->primary();
                $table->string('conversation_id', 36)->index();
                $table->string('participant_type')->nullable();
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->string('agent');
                $table->string('role', 25);
                $table->text('content');
                $table->text('attachments')->default('[]');
                $table->text('tool_calls')->default('[]');
                $table->text('tool_results')->default('[]');
                $table->text('usage')->default('[]');
                $table->text('meta')->default('[]');
                $table->text('approval_state')->nullable();
                $table->timestamps();

                $table->foreign('conversation_id')->references('id')->on($conversationTable)->cascadeOnDelete();
                $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'agent_messages_conversation_idx');
                $table->index(['participant_type', 'participant_id'], 'agent_messages_participant_idx');
            });
        }

        Schema::table($conversationTable, function (Blueprint $table) use ($conversationTable): void {
            if (! Schema::hasColumn($conversationTable, 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('title');
            }
        });

        Schema::create('ai_workspace_runs', function (Blueprint $table) use ($conversationTable): void {
            $table->uuid('id')->primary();
            $table->string('conversation_id', 36);
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('admin_username_snapshot', 100);
            $table->unsignedInteger('admin_auth_version')->nullable();
            $table->uuid('parent_run_id')->nullable();
            $table->string('request_key', 120)->nullable();
            $table->string('mode', 30)->default('workflow');
            $table->string('state', 40)->default('received');
            $table->text('prompt');
            $table->string('intent', 120)->nullable();
            $table->json('prompt_versions')->nullable();
            $table->decimal('resolution_score', 5, 4)->nullable();
            $table->json('candidate_capabilities')->nullable();
            $table->json('known_parameters')->nullable();
            $table->json('missing_parameters')->nullable();
            $table->json('plan')->nullable();
            $table->unsignedInteger('plan_version')->default(1);
            $table->string('plan_digest', 64)->nullable();
            $table->json('capability_versions')->nullable();
            $table->string('parameter_digest', 64)->nullable();
            $table->string('target_digest', 64)->nullable();
            $table->string('risk_level', 20)->default('low');
            $table->longText('answer')->nullable();
            $table->text('status_message')->nullable();
            $table->boolean('system_operations_executed')->default(false);
            $table->unsignedBigInteger('event_sequence')->default(0);
            $table->unsignedInteger('state_version')->default(1);
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->string('resolution_lease_owner', 120)->nullable();
            $table->timestamp('resolution_lease_expires_at')->nullable();
            $table->unsignedInteger('resolution_attempts')->default(0);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('payload_pruned_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on($conversationTable)->cascadeOnDelete();
            $table->unique(['admin_id', 'request_key'], 'ai_workspace_runs_admin_request_unique');
            $table->index(['admin_id', 'created_at']);
            $table->index(['state', 'updated_at']);
        });

        // PostgreSQL compiles self-referencing foreign keys before the primary
        // key command when both are declared on the same create blueprint.
        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            $table->foreign('parent_run_id')->references('id')->on('ai_workspace_runs')->nullOnDelete();
        });

        Schema::create('ai_workspace_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->unsignedInteger('position');
            $table->string('capability_key', 120);
            $table->string('capability_name', 180)->nullable();
            $table->string('capability_version', 40);
            $table->string('state', 40)->default('pending');
            $table->string('risk_level', 20)->default('low');
            $table->string('execution_scope', 40);
            $table->string('approval_policy', 30)->default('none');
            $table->json('result_contract')->nullable();
            $table->json('parameters');
            $table->json('depends_on')->default('[]');
            $table->json('input_bindings')->default('{}');
            $table->timestamp('bindings_resolved_at')->nullable();
            $table->json('target_summary')->nullable();
            $table->json('result_summary')->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->boolean('requires_approval')->default(false);
            $table->boolean('external_operation')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('lease_owner', 120)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('ai_workspace_runs')->cascadeOnDelete();
            $table->unique(['run_id', 'position']);
            $table->index(['state', 'lease_expires_at']);
        });

        Schema::create('ai_workspace_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('step_id')->nullable();
            $table->string('capability_key', 120);
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('admin_username_snapshot', 100);
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('plan_version');
            $table->json('capability_versions');
            $table->string('parameter_digest', 64);
            $table->string('target_digest', 64);
            $table->text('decision_reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('ai_workspace_runs')->cascadeOnDelete();
            $table->foreign('step_id')->references('id')->on('ai_workspace_steps')->nullOnDelete();
            $table->index(['run_id', 'status', 'expires_at']);
        });

        Schema::create('ai_workspace_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('step_id')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('created_by_username_snapshot', 100);
            $table->string('type', 60);
            $table->string('name', 180);
            $table->string('data_classification', 30)->default('internal');
            $table->longText('content')->nullable();
            $table->json('payload')->nullable();
            $table->string('source_route', 180)->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('ai_workspace_runs')->cascadeOnDelete();
            $table->foreign('step_id')->references('id')->on('ai_workspace_steps')->nullOnDelete();
            $table->unique('step_id');
            $table->index(['run_id', 'type']);
            $table->index('expires_at');
        });

        Schema::create('ai_workspace_external_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('step_id');
            $table->string('execution_key', 160);
            $table->string('capability_key', 120);
            $table->string('target_type', 80);
            $table->string('target_id', 120);
            $table->string('status', 30)->default('prepared');
            $table->string('request_digest', 64);
            $table->string('target_digest', 64);
            $table->json('request_payload')->nullable();
            $table->json('remote_result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('ai_workspace_runs')->cascadeOnDelete();
            $table->foreign('step_id')->references('id')->on('ai_workspace_steps')->cascadeOnDelete();
            $table->unique(['execution_key', 'target_type', 'target_id'], 'aiw_external_operations_execution_target_unique');
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_workspace_external_operations');
        Schema::dropIfExists('ai_workspace_artifacts');
        Schema::dropIfExists('ai_workspace_approvals');
        Schema::dropIfExists('ai_workspace_steps');
        Schema::dropIfExists('ai_workspace_runs');
        // Conversation tables may be shared with Laravel AI. Keep them during
        // a workspace rollback to avoid deleting unrelated conversation data.
        if (Schema::hasTable('ai_models')) {
            Schema::table('ai_models', function (Blueprint $table): void {
                if (Schema::hasColumn('ai_models', 'ai_workspace_structured_output_verified_at')) {
                    $table->dropColumn('ai_workspace_structured_output_verified_at');
                }
                if (Schema::hasColumn('ai_models', 'ai_workspace_structured_output_status')) {
                    $table->dropColumn('ai_workspace_structured_output_status');
                }
            });
        }
    }
};
