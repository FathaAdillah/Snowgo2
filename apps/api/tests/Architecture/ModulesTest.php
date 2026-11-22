<?php

use App\Support\Modules\ModuleServiceProvider;

arch('debug helpers are not shipped')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('support code does not depend on modules')
    ->expect('App\Support')
    ->not->toUse('App\Modules');

$config = require __DIR__.'/../../config/modules.php';
arch('registered module providers extend the module base provider')
    ->expect($config['providers'])
    ->toHaveSuffix('ServiceProvider')
    ->toExtend(ModuleServiceProvider::class);
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
