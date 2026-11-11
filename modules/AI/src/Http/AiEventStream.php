<?php

declare(strict_types=1);

namespace Modules\AI\Http;

use Closure;
use Modules\AI\Data\AiResult;
use Modules\AI\Exceptions\AiOutputBlocked;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sends a guarded AI stream to the browser as Server-Sent Events:
 * "text" (checked pieces), then "done" (request id) or "withheld" (the screen must replace what
 * was shown with the withheld message) or "error" (a friendly message, never internals).
 */
final class AiEventStream
{
    /**
     * @param  Closure(Closure(string): void): AiResult  $produce  e.g. fn ($emit) => $gateway->stream($task, $emit)
     */
    public static function response(Closure $produce): StreamedResponse
    {
        return new StreamedResponse(function () use ($produce): void {
            $send = function (string $event, array $data): void {
                echo "event: {$event}\n".'data: '.json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $result = $produce(fn (string $text) => $send('text', ['text' => $text]));
                $send('done', ['request' => $result->requestUlid]);
            } catch (AiOutputBlocked) {
                $send('withheld', ['message' => 'This answer was withheld because it referred to information you do not have access to.']);
            } catch (Throwable) {
                $send('error', ['message' => 'The AI assistant is unavailable right now. Please try again.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
