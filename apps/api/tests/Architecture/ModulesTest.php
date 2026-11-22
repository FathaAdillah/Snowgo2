<?php

use App\Support\Modules\ModuleServiceProvider;

arch('debug helpers are not shipped')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('support code does not depend on modules')
    ->expect('App\Support')
    ->not->toUse('App\Modules');

arch('module providers extend the module base provider')
    ->expect('App\Modules')
    ->classes()
    ->toHaveSuffix('ServiceProvider')
    ->ignoring(['App\Modules\Foundation\Http', 'App\Modules\Foundation\Health'])
    ->toExtend(ModuleServiceProvider::class)
    ->ignoring(['App\Modules\Foundation\Http', 'App\Modules\Foundation\Health']);

$config = require __DIR__.'/../../config/modules.php';
$modules = array_map(fn (string $provider): string => explode('\\', $provider)[2], $config['providers']);

foreach ($modules as $module) {
    $others = array_values(array_map(
        fn (string $other): string => "App\\Modules\\{$other}\\Models",
        array_filter($modules, fn (string $other): bool => $other !== $module),
    ));

    arch("{$module} does not use ORM models owned by other modules")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($others);
}
