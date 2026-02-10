<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Ports\Storage\GooglePhotos;

/**
 * Combined interface for UploadJob repository.
 * Extends both Read and Write interfaces for backward compatibility.
 * New code should prefer using separate Read/Write interfaces.
 *
 * @deprecated Consider using UploadJobRepositoryReadPort and UploadJobRepositoryWritePort separately
 */
interface UploadJobRepositoryPort extends UploadJobRepositoryReadPort, UploadJobRepositoryWritePort
{
}
