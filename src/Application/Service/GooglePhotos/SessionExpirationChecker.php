<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Application\Service\GooglePhotos;

use SortingPhotosByDate\Application\Service\GooglePhotos\Exception\SessionExpiredException;
use SortingPhotosByDate\Ports\LoggerPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\GooglePhotosApiClientPort;
use SortingPhotosByDate\Ports\Storage\GooglePhotos\UploadJobRepositoryPort;

final readonly class SessionExpirationChecker
{
    public function __construct(
        private UploadJobRepositoryPort $jobRepository,
        private GooglePhotosApiClientPort $apiClient,
        private LoggerPort $logger,
    ) {
    }

    public function checkAndRenewExpiredSessions(): void
    {
        $expiredJobs = $this->jobRepository->findWithExpiredSessions();

        if ([] === $expiredJobs) {
            return;
        }

        $this->logger->info('Checking expired sessions', [
            'count' => \count($expiredJobs),
        ]);

        foreach ($expiredJobs as $job) {
            try {
                $session = $job->getResumableSession();
                if (null === $session) {
                    continue;
                }

                // Try to query status
                $status = $this->apiClient->queryUploadStatus($session->getSessionUri());

                if ($status->isComplete()) {
                    // File already uploaded
                    $uploadToken = $status->getUploadToken();
                    if (null === $uploadToken) {
                        $this->logger->warning('Upload complete but no token received', [
                            'job_id' => $job->getId()->getId(),
                        ]);
                        continue;
                    }

                    $job = $job->completeUpload($uploadToken);
                    $this->jobRepository->save($job);

                    $this->logger->info('File was already uploaded, token retrieved', [
                        'job_id' => $job->getId()->getId(),
                    ]);
                } else {
                    // Session expired, but file not uploaded
                    $job = $job->markSessionExpired($status->getUploadedBytes());
                    $this->jobRepository->save($job);

                    $this->logger->warning('Session expired, file not uploaded', [
                        'job_id' => $job->getId()->getId(),
                        'last_known_bytes' => $status->getUploadedBytes(),
                    ]);
                }
            } catch (SessionExpiredException) {
                // Session definitely expired
                $job = $job->markSessionExpired(0);
                $this->jobRepository->save($job);

                $this->logger->warning('Session expired (404/410)', [
                    'job_id' => $job->getId()->getId(),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Error checking session expiration', [
                    'job_id' => $job->getId()->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
