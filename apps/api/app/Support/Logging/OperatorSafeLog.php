<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use LogicException;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;

final class OperatorSafeLog
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (! $monolog instanceof MonologLogger) {
            throw new LogicException('Operator log requires Monolog.');
        }
        foreach ($monolog->getHandlers() as $handler) {
            if (! $handler instanceof ProcessableHandlerInterface) {
                throw new LogicException('Operator log handler must support sanitization.');
            }
            $handler->pushProcessor(self::sanitize(...));
        }
    }

    public static function sanitize(LogRecord $record): LogRecord
    {
        // Never copy free-form messages, exception objects, request payloads or context.
        return $record->with(channel: 'operator', message: 'Application event (details in restricted Docker logs)', context: [], extra: []);
    }
}
