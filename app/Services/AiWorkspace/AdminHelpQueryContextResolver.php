<?php

namespace App\Services\AiWorkspace;

use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use Illuminate\Support\Str;

final class AdminHelpQueryContextResolver
{
    /** @return array{retrieval_query:string,previous_user_question:?string,previous_sources:list<array<string,mixed>>,followup_expanded:bool} */
    public function resolve(AiConversation $conversation, string $excludedMessageId, string $question): array
    {
        $messages = AiConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereKeyNot($excludedMessageId)
            ->whereIn('role', ['user', 'assistant'])
            ->latest('created_at')
            ->latest('id')
            ->limit(12)
            ->get()
            ->values();
        $previousUserIndex = $messages->search(
            static fn (AiConversationMessage $message): bool => $message->role === 'user',
        );
        $previousUser = $previousUserIndex === false ? null : $messages->get($previousUserIndex);
        $previousAssistant = $previousUserIndex === false
            ? null
            : $messages->take($previousUserIndex)
                ->first(static fn (AiConversationMessage $message): bool => $message->role === 'assistant');
        $previousQuestion = $previousUser instanceof AiConversationMessage
            ? trim((string) $previousUser->content)
            : null;
        $previousSources = $previousAssistant instanceof AiConversationMessage
            && is_array($previousAssistant->meta['knowledge_sources'] ?? null)
                ? array_values(array_filter($previousAssistant->meta['knowledge_sources'], 'is_array'))
                : [];
        $expanded = $previousQuestion !== null && $this->looksLikeFollowup($question);
        $sourceHints = collect($previousSources)
            ->flatMap(static fn (array $source): array => [
                trim((string) ($source['feature_id'] ?? '')),
                trim((string) ($source['section_path'] ?? '')),
            ])
            ->filter()
            ->unique()
            ->take(3)
            ->implode(' ');
        $retrievalQuery = $expanded
            ? trim($question."\n".$previousQuestion."\n".$sourceHints)
            : trim($question);

        return [
            'retrieval_query' => Str::limit($retrievalQuery, 1200, ''),
            'previous_user_question' => $previousQuestion,
            'previous_sources' => $previousSources,
            'followup_expanded' => $expanded,
        ];
    }

    private function looksLikeFollowup(string $question): bool
    {
        $question = Str::lower(Str::squish($question));
        if ($question === '' || Str::length($question) > 48) {
            return false;
        }

        return preg_match('/(?:这个|那个|它|这里|然后|接下来|怎么设置|怎么做|为什么|详细(?:点|说明|说说)?|继续|this|that|it|then|next|how about|more detail)/iu', $question) === 1;
    }
}
