<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AiConversation;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Support\AdminWeb;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

final class AdminRecentActivityService
{
    private const FALLBACK_TIME = '1970-01-01T00:00:00.000000Z';

    public function __construct(private readonly AiConversationRepository $conversations) {}

    /** @return array{items:list<array{id:string,kind:string,title:string,href:string,archive_url:?string}>,next_cursor:?string,has_more:bool} */
    public function page(Admin $admin, int $limit = 10, ?string $cursor = null): array
    {
        $limit = max(1, min(50, $limit));
        $boundary = $cursor === null ? null : $this->decodeCursor($cursor);
        $items = $this->chatItems($admin, $limit + 1, $boundary);
        $hasMore = count($items) > $limit;
        $visible = array_slice($items, 0, $limit);
        $last = $hasMore ? end($visible) : false;

        return [
            'items' => array_map(fn (array $item): array => $this->publicItem($item), $visible),
            'next_cursor' => is_array($last) ? $this->encodeCursor($last) : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  array{occurred_at:string,id:string}|null  $boundary
     * @return list<array{id:string,kind:string,title:string,href:string,occurred_at:string,archive_url:string}>
     */
    private function chatItems(Admin $admin, int $limit, ?array $boundary): array
    {
        $boundaryTime = null;
        $beforeId = null;
        if ($boundary !== null) {
            $boundaryTime = CarbonImmutable::parse($boundary['occurred_at'])
                ->setTimezone((string) config('app.timezone', 'UTC'));
            $beforeId = $boundary['id'];
        }

        return $this->conversations
            ->listRecentForAdmin($admin, $limit, $boundaryTime, false, $beforeId)
            ->map(function (AiConversation $conversation): array {
                $id = (string) $conversation->getKey();

                return [
                    'id' => $id,
                    'kind' => 'chat',
                    'title' => (string) $conversation->title,
                    'href' => AdminWeb::routePath('admin.ai-workspace').'?conversation='.rawurlencode($id),
                    'occurred_at' => $this->normalizeTime($conversation->updated_at),
                    'archive_url' => AdminWeb::routePath('admin.ai-workspace.conversations.archive', ['conversation' => $id]),
                ];
            })
            ->all();
    }

    /** @param array<string,mixed> $item @return array{id:string,kind:string,title:string,href:string,archive_url:?string} */
    private function publicItem(array $item): array
    {
        return [
            'id' => $item['id'],
            'kind' => $item['kind'],
            'title' => $item['title'],
            'href' => $item['href'],
            'archive_url' => $item['archive_url'],
        ];
    }

    /** @param array{id:string,kind:string,occurred_at:string} $item */
    private function encodeCursor(array $item): string
    {
        $json = json_encode([
            'v' => 1,
            'filter' => 'chat',
            'occurred_at' => $item['occurred_at'],
            'kind' => 'chat',
            'id' => $item['id'],
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{occurred_at:string,id:string} */
    private function decodeCursor(string $cursor): array
    {
        try {
            $padding = (4 - strlen($cursor) % 4) % 4;
            $decoded = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', $padding), true);
            if ($decoded === false) {
                throw new JsonException('Invalid encoding.');
            }
            $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($payload)
                || ($payload['v'] ?? null) !== 1
                || ($payload['filter'] ?? null) !== 'chat'
                || ($payload['kind'] ?? null) !== 'chat'
                || ! is_string($payload['id'] ?? null)
                || $payload['id'] === ''
                || strlen($payload['id']) > 180
                || ! is_string($payload['occurred_at'] ?? null)) {
                throw new JsonException('Invalid cursor payload.');
            }

            return [
                'occurred_at' => $this->normalizeTime($payload['occurred_at'], true),
                'id' => $payload['id'],
            ];
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'cursor' => [__('validation.invalid', ['attribute' => 'cursor'])],
            ]);
        }
    }

    private function normalizeTime(mixed $value, bool $strict = false): string
    {
        if ($value === null || $value === '') {
            if ($strict) {
                throw new JsonException('Missing timestamp.');
            }

            return self::FALLBACK_TIME;
        }

        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable $exception) {
            if ($strict) {
                throw $exception;
            }

            return self::FALLBACK_TIME;
        }
    }
}
