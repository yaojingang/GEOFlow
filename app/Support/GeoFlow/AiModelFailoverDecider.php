<?php

namespace App\Support\GeoFlow;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\FailoverableException;
use Throwable;

final class AiModelFailoverDecider
{
    public function isPermanentProviderFailure(Throwable $exception): bool
    {
        $current = $exception;

        for ($depth = 0; $depth < 8 && $current instanceof Throwable; $depth++) {
            if ($current instanceof RequestException && $current->response !== null) {
                $status = $current->response->status();

                return $status >= 400
                    && $status < 500
                    && ! in_array($status, [408, 425, 429], true);
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    public function shouldFailover(Throwable $exception): bool
    {
        $current = $exception;
        $transientFailure = false;

        for ($depth = 0; $depth < 8 && $current instanceof Throwable; $depth++) {
            if ($current instanceof RequestException && $current->response !== null) {
                $status = $current->response->status();

                if ($status >= 400 && $status < 500 && ! in_array($status, [408, 425, 429], true)) {
                    return false;
                }

                if ($status >= 500 || in_array($status, [408, 425, 429], true)) {
                    $transientFailure = true;
                }
            }

            if ($current instanceof FailoverableException || $current instanceof ConnectionException) {
                $transientFailure = true;
            }

            $current = $current->getPrevious();
        }

        $message = mb_strtolower($exception->getMessage(), 'UTF-8');

        return $transientFailure
            || str_contains($message, 'ai返回空正文')
            || str_contains($message, 'ai 返回空流式响应');
    }
}
