<?php

declare(strict_types=1);

use SortingPhotosByDate\Services\Sorter;
use SortingPhotosByDate\Tests\Util\SimpleLogger;

require_once __DIR__.'/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '550M');
ini_set('display_startup_errors', '1');

// $unsortedPhotosDir = '/run/user/1000/gvfs/mtp:host=Xiaomi_Mi_9T_Pro_8f997d4e/Внутренний общий накопитель/DCIM';
$unsortedPhotosDir = '/run/user/1000/gvfs/mtp:host=Xiaomi_Mi_9T_Pro_8f997d4e/Внутренний общий накопитель/Pictures';
// $unsortedPhotosDir = '/run/user/1000/gvfs/mtp:host=Xiaomi_Mi_9T_Pro_8f997d4e/Внутренний общий накопитель/Android/media/com.whatsapp/WhatsApp/Media';
// $unsortedPhotosDir = '/media/isavins/KINGSTON/video-s-telefona';
// $copyToDir = '/media/isavins/T7 Shield/MY-MEDIA_2025';
$copyToDir = '/media/isavins/ADATA SH14/2025';

try {
    $logger = new SimpleLogger();
    $unsortedPhotosSize = getDirectorySize($unsortedPhotosDir);
    $copyToDirSizeBefore = getDirectorySize($copyToDir);

    $logger->info('Unsorted Photos Directory Size: '.formatBytes($unsortedPhotosSize));
    $logger->info('Copy To Directory Size Before: '.formatBytes($copyToDirSizeBefore));
    $logger->info('It is expected that after copying it will become: '.formatBytes($unsortedPhotosSize + $copyToDirSizeBefore));

    $sorter = new Sorter($unsortedPhotosDir, $copyToDir, $logger);
    $sorter->process();

    $copyToDirSizeAfter = getDirectorySize($copyToDir);

    $logger->info('Copy To Directory Size After: '.formatBytes($copyToDirSizeAfter));

    if ($copyToDirSizeAfter !== ($unsortedPhotosSize + $copyToDirSizeBefore)) {
        $logger->warning('ALERT!!! The size of the directory after copying did not match the expected size');
    }

} catch (Throwable $throwable) {
    $logger->error($throwable::class.': '.$throwable->getMessage());
}

/**
 * Recursively calculates the size of the directory.
 */
function getDirectorySize(string $directory): int
{
    $size = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }

    return $size;
}

/**
 * Formats the size in bytes in a readable form (KB, MB, GB, etc.).
 */
function formatBytes(int $size): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $size >= 1024 && $i < 4; ++$i) {
        $size /= 1024;
    }

    return round($size, 2).' '.$units[$i];
}
