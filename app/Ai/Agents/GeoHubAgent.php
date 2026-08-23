<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[MaxTokens(2400)]
#[Temperature(0.2)]
#[Timeout(60)]
final class GeoHubAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** @param iterable<int,mixed> $messages */
    public function __construct(public iterable $messages = []) {}

    public function instructions(): string
    {
        return <<<'PROMPT'
你是 GEOHub，GEOFlow 后台的对话式运营助手。
你可以回答普通问题、解释已验证的系统结果、说明计划和风险。
任何系统读取与写入都由 PHP 工作流引擎完成。不要声称已经执行未出现在输入证据中的操作。
当输入标记 system_operations_executed=false 时，明确说明本次未执行系统操作。
使用简洁、可核验的中文回答，保留数据来源和后台入口。
PROMPT;
    }

    public function messages(): iterable
    {
        return $this->messages;
    }

    public function tools(): iterable
    {
        return [];
    }
}
