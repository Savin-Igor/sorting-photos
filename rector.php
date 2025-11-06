<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
           ->withSkip([
               __DIR__.'/vendor',
               __DIR__.'/config',
               __DIR__.'/var',
               // Skip test directories that may have legacy code
               __DIR__.'/tests/src/Entities',
               __DIR__.'/tests/src/Services',
               // Skip ContainerFactory.php - first-class callable conflicts with PHPStan
               __DIR__.'/src/Infrastructure/Config/ContainerFactory.php',
           ])
    ->withSets([
        LevelSetList::UP_TO_PHP_84,
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
        SetList::DEAD_CODE,
    ]);
