<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\Rector\ClassMethod\PHPArrayConfigToConfigBuilderRule;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/config/packages']);

    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');

    $rectorConfig->autoloadPaths([__DIR__ .'/vendor/autoload_runtime.php']);

    // basic rules
    $rectorConfig->importNames();
    $rectorConfig->importShortClasses(false);

    $rectorConfig->rule(PHPArrayConfigToConfigBuilderRule::class);
};
