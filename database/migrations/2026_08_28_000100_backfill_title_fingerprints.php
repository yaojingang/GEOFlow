<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::table('titles')
            ->whereNull('title_fingerprint')
            ->orderBy('id')
            ->chunkById(200, function ($titles): void {
                $this->backfillBatch($titles);
            });
    }

    public function down(): void
    {
        // Canonical fingerprints are retained because later title writes depend on them.
    }

    private function normalizeText(string $title): string
    {
        $normalized = $title;
        if (class_exists(Normalizer::class)) {
            $candidate = Normalizer::normalize($title, Normalizer::FORM_KC);
            if (is_string($candidate)) {
                $normalized = $candidate;
            }
        }
        $normalized = preg_replace(
            '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}\x{2060}\x{FEFF}]/u',
            '',
            $normalized,
        );
        $normalized = preg_replace('/\s+/u', ' ', (string) $normalized);

        return trim((string) $normalized);
    }

    private function backfillBatch(iterable $titles): void
    {
        $lastUniqueConflict = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                DB::transaction(function () use ($titles): void {
                    $candidates = collect($titles)
                        ->map(fn (object $title): array => [
                            'id' => (int) $title->id,
                            'library_id' => (int) $title->library_id,
                            'fingerprint' => hash('sha256', $this->normalizeText((string) $title->title)),
                        ])
                        ->unique(static fn (array $candidate): string => $candidate['library_id'].'|'.$candidate['fingerprint'])
                        ->values();
                    $claimed = DB::table('titles')
                        ->whereIn('library_id', $candidates->pluck('library_id')->unique()->all())
                        ->whereIn('title_fingerprint', $candidates->pluck('fingerprint')->unique()->all())
                        ->whereNotNull('title_fingerprint')
                        ->get(['library_id', 'title_fingerprint'])
                        ->mapWithKeys(static fn (object $title): array => [
                            ((int) $title->library_id).'|'.(string) $title->title_fingerprint => true,
                        ])
                        ->all();
                    $pending = $candidates->reject(
                        static fn (array $candidate): bool => isset($claimed[$candidate['library_id'].'|'.$candidate['fingerprint']])
                    )->values();

                    if ($pending->isEmpty()) {
                        return;
                    }

                    $cases = implode(' ', array_fill(0, $pending->count(), 'WHEN ? THEN ?'));
                    $ids = implode(', ', array_fill(0, $pending->count(), '?'));
                    $bindings = $pending
                        ->flatMap(static fn (array $candidate): array => [$candidate['id'], $candidate['fingerprint']])
                        ->concat($pending->pluck('id'))
                        ->all();

                    DB::update(
                        "UPDATE titles SET title_fingerprint = CASE id {$cases} ELSE title_fingerprint END WHERE id IN ({$ids}) AND title_fingerprint IS NULL",
                        $bindings,
                    );
                }, 3);

                return;
            } catch (QueryException $exception) {
                if (! $this->isUniqueConflict($exception)) {
                    throw $exception;
                }
                $lastUniqueConflict = $exception;
            }
        }

        throw $lastUniqueConflict;
    }

    private function isUniqueConflict(QueryException $exception): bool
    {
        if ((string) $exception->getCode() === '23505') {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return (string) $exception->getCode() === '23000'
            && (str_contains($message, 'unique constraint') || str_contains($message, 'duplicate entry'));
    }
};
