<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Services;

use Psr\Log\LoggerInterface;
use SortingPhotosByDate\Entities\Image;
use SortingPhotosByDate\Entities\Video;
use SortingPhotosByDate\Contracts\FileInterface;
use SortingPhotosByDate\Exceptions\SortingPhotosException;

final class Sorter
{
    private const PERMISSIONS = 0777;

    private string $copyToDirectory;
    private string $catalogUnsortedPhotos;
    private LoggerInterface $logger;

    public function __construct(
        string $catalogUnsortedPhotos,
        string $copyToDirectory,
        LoggerInterface $logger
    ) {
        if (!is_dir($catalogUnsortedPhotos)) {
            throw SortingPhotosException::noSuchDirectory($catalogUnsortedPhotos);
        }

        $this->makeDirIfNotExist($copyToDirectory);
        $this->copyToDirectory = $copyToDirectory;
        $this->catalogUnsortedPhotos = $catalogUnsortedPhotos;
        $this->logger = $logger;
    }

    public function process(): bool
    {
        $this->processDirectory($this->catalogUnsortedPhotos);

        return true;
    }

    private function processDirectory(string $directory): void
    {
        $files = scandir($directory);
        if (false === $files) {
            throw SortingPhotosException::directoryIsEmpty($directory);
        }

        foreach ($files as $fileName) {
            if (in_array($fileName, ['.', '..', '.DS_Store', '.temp'], true)) {
                continue;
            }

            $filePath = $directory . '/' . $fileName;

            if (is_dir($filePath)) {
                $this->processDirectory($filePath);
            } else {
                try {
                    $file = exif_imagetype($filePath)
                        ? new Image($filePath)
                        : new Video($filePath);

                    $this->copyFile($file, $filePath);
                } catch (SortingPhotosException $exception) {
                    $this->logger->warning($exception->getMessage());
                } catch (\Throwable $throwable) {
                    $this->logger->error(
                        sprintf('[%s] %s', $throwable::class, $throwable->getMessage()),
                        [
                            'filePath' => $filePath,
                            'fileName' => $fileName,
                        ]
                    );
                }
            }
        }
    }

    private function makeDirIfNotExist(string $dir): void
    {
        if (is_dir($dir)) {
            $this->checkPermissionsDirAndIfNotAdd($dir);

            return;
        }

        if (!mkdir($dir, self::PERMISSIONS, true)) {
            throw SortingPhotosException::failedCreateFolder($dir);
        }
    }

    private function checkPermissionsDirAndIfNotAdd(string $dir): void
    {
        $permissions = fileperms($dir);
        if (self::PERMISSIONS !== substr(sprintf('%o', $permissions), -4)) {
            chmod($dir, self::PERMISSIONS);
        }
    }

    private function copyFile(FileInterface $file, string $sourceFile): void
    {
        $copyToDir = sprintf(
            '%s/%s/%s/%s',
            $this->copyToDirectory,
            $file->getType(),
            $file->getDateTime()->year,
            $file->getDateTime()->format('Y-m')
        );

        $this->makeDirIfNotExist($copyToDir);

        $newFile = sprintf('%s/%s', $copyToDir, $file->getName());
        if (file_exists($newFile)) {
            echo 'File is exist' . PHP_EOL;
            // throw SortingPhotosException::fileExists($newFile);
        }

        if (!copy($sourceFile, $newFile)) {
            throw SortingPhotosException::notCopyFile($file->getName());
        }
    }
}
