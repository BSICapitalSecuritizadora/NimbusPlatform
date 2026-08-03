<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\RotatingFileHandler;

it('streams production logs to stderr and retains daily fallback logs for thirty days', function () {
    $productionEnvironment = File::get(base_path('.env.example.production'));
    $localEnvironment = File::get(base_path('.env.example'));
    $dailyHandler = collect(Log::channel('daily')->getLogger()->getHandlers())
        ->first(fn (mixed $handler): bool => $handler instanceof RotatingFileHandler);
    $maxFiles = new ReflectionProperty(RotatingFileHandler::class, 'maxFiles');
    $maxFiles->setAccessible(true);

    expect($productionEnvironment)
        ->toMatch('/^LOG_CHANNEL=stack$/m')
        ->toMatch('/^LOG_STACK=stderr$/m')
        ->toMatch('/^LOG_DAILY_DAYS=30$/m')
        ->and($localEnvironment)
        ->toMatch('/^LOG_STACK=daily$/m')
        ->toMatch('/^LOG_DAILY_DAYS=30$/m')
        ->and(config('logging.channels.daily.driver'))->toBe('daily')
        ->and(config('logging.channels.daily.days'))->toBe(30)
        ->and($dailyHandler)->toBeInstanceOf(RotatingFileHandler::class)
        ->and($maxFiles->getValue($dailyHandler))->toBe(30)
        ->and(config('logging.channels.stderr.handler_with.stream'))->toBe('php://stderr');
});
