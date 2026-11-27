<?php

use App\Support\Logging\OperatorSafeLog;
use Monolog\Level;
use Monolog\LogRecord;

it('removes free-form messages and all context from operator log records', function () {
    $record = new LogRecord(new DateTimeImmutable, 'production', Level::Error, 'password=secret', ['token' => 'secret'], ['cookie' => 'secret']);
    $safe = OperatorSafeLog::sanitize($record);
    expect($safe->message)->not->toContain('secret');
    expect($safe->context)->toBe([]);
    expect($safe->extra)->toBe([]);
    expect($safe->level)->toBe(Level::Error);
    expect($safe->channel)->toBe('operator');
    expect($record->message)->toBe('password=secret');
});
