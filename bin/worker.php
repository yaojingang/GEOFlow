<?php
/**
 * GEO+AI内容生成系统 - 单常驻 worker
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';
require_once $projectRoot . '/includes/functions.php';
require_once $projectRoot . '/includes/job_queue_service.php';
require_once $projectRoot . '/includes/ai_engine.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

$workerId = gethostname() . ':' . getmypid();
$queueService = new JobQueueService($db);
$aiEngine = new AIEngine($db);
$idleSleepSeconds = 5;

function taskShouldStop(PDO $db, int $taskId): bool {
    $stmt = $db->prepare("SELECT status, COALESCE(schedule_enabled, 1) AS schedule_enabled FROM tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        return true;
    }

    return ($task['status'] ?? 'active') !== 'active' || (int) ($task['schedule_enabled'] ?? 1) !== 1;
}

function isStopRequestedResult(PDO $db, int $taskId, string $message): bool {
    if (taskShouldStop($db, $taskId)) {
        return true;
    }

    return str_contains($message, '任务已被管理员停止')
        || str_contains($message, '管理员手动停止')
        || str_contains($message, '任务未激活');
}

function heartbeat(PDO $db, string $workerId, string $status = 'idle', ?int $jobId = null, array $meta = []): void {
    $stmt = $db->prepare("
        INSERT INTO worker_heartbeats (worker_id, status, current_job_id, last_seen_at, meta, created_at, updated_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT(worker_id) DO UPDATE SET
            status = excluded.status,
            current_job_id = excluded.current_job_id,
            last_seen_at = CURRENT_TIMESTAMP,
            meta = excluded.meta,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $workerId,
        $status,
        $jobId,
        json_encode($meta, JSON_UNESCAPED_UNICODE)
    ]);
}

echo '[' . date('Y-m-d H:i:s') . "] worker 启动: {$workerId}\n";
heartbeat($db, $workerId, 'idle', null, ['pid' => getmypid()]);

$aiEngine->setHeartbeatCallback(function (string $stage, array $context = []) use ($db, $workerId): void {
    $jobId = isset($context['job_id']) ? (int) $context['job_id'] : null;
    heartbeat($db, $workerId, 'running', $jobId, array_merge($context, ['pid' => getmypid(), 'stage' => $stage]));
});

while (true) {
    try {
        heartbeat($db, $workerId, 'idle', null, ['pid' => getmypid()]);
        $queueService->recoverStaleJobs();
        $job = $queueService->claimNextJob($workerId);

        if (!$job) {
            sleep($idleSleepSeconds);
            continue;
        }

        $startedAt = microtime(true);
        heartbeat($db, $workerId, 'running', (int) $job['id'], [
            'job_id' => (int) $job['id'],
            'task_id' => (int) $job['task_id'],
            'pid' => getmypid(),
            'stage' => 'claimed'
        ]);
        echo '[' . date('Y-m-d H:i:s') . "] 领取 job #{$job['id']}，任务 {$job['task_id']}\n";

        $aiEngine->setHeartbeatCallback(function (string $stage, array $context = []) use ($db, $workerId, $job): void {
            heartbeat($db, $workerId, 'running', (int) $job['id'], array_merge($context, [
                'job_id' => (int) $job['id'],
                'task_id' => (int) $job['task_id'],
                'pid' => getmypid(),
                'stage' => $stage
            ]));

            if (taskShouldStop($db, (int) $job['task_id'])) {
                throw new RuntimeException('任务已被管理员停止');
            }
        });
        try {
            $result = $aiEngine->executeTask((int) $job['task_id']);
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $message = $e->getMessage();
            if (isStopRequestedResult($db, (int) $job['task_id'], $message)) {
                $queueService->cancelJob((int) $job['id'], (int) $job['task_id'], '管理员手动停止');
                heartbeat($db, $workerId, 'idle', null, ['last_job_id' => (int) $job['id'], 'pid' => getmypid()]);
                echo '[' . date('Y-m-d H:i:s') . "] job #{$job['id']} 已按停止请求取消\n";
                continue;
            }

            $queueService->failJob(
                (int) $job['id'],
                (int) $job['task_id'],
                $message,
                $durationMs
            );
            heartbeat($db, $workerId, 'idle', null, ['last_job_id' => (int) $job['id'], 'pid' => getmypid()]);
            echo '[' . date('Y-m-d H:i:s') . "] job #{$job['id']} 执行异常: {$e->getMessage()}\n";
            continue;
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (!empty($result['success'])) {
            $queueService->completeJob(
                (int) $job['id'],
                (int) $job['task_id'],
                isset($result['article_id']) ? (int) $result['article_id'] : null,
                $durationMs,
                array_merge(
                    ['title' => $result['title'] ?? '', 'message' => $result['message'] ?? ''],
                    is_array($result['meta'] ?? null) ? $result['meta'] : []
                )
            );
            heartbeat($db, $workerId, 'idle', null, ['last_job_id' => (int) $job['id'], 'pid' => getmypid()]);
            echo '[' . date('Y-m-d H:i:s') . "] job #{$job['id']} 执行成功\n";
            continue;
        }

        $resultError = $result['error'] ?? '未知错误';
        if (isStopRequestedResult($db, (int) $job['task_id'], $resultError)) {
            $queueService->cancelJob((int) $job['id'], (int) $job['task_id'], '管理员手动停止');
            heartbeat($db, $workerId, 'idle', null, ['last_job_id' => (int) $job['id'], 'pid' => getmypid()]);
            echo '[' . date('Y-m-d H:i:s') . "] job #{$job['id']} 已按停止请求取消\n";
            continue;
        }

        $queueService->failJob(
            (int) $job['id'],
            (int) $job['task_id'],
            $resultError,
            $durationMs
        );
        heartbeat($db, $workerId, 'idle', null, ['last_job_id' => (int) $job['id'], 'pid' => getmypid()]);
        echo '[' . date('Y-m-d H:i:s') . "] job #{$job['id']} 执行失败: {$resultError}\n";
    } catch (Throwable $e) {
        heartbeat($db, $workerId, 'error', null, ['message' => $e->getMessage(), 'pid' => getmypid()]);
        echo '[' . date('Y-m-d H:i:s') . '] worker 异常: ' . $e->getMessage() . "\n";
        write_log('Worker 异常: ' . $e->getMessage(), 'ERROR');
        sleep($idleSleepSeconds);
    }
}
