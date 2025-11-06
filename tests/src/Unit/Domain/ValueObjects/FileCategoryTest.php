<?php

declare(strict_types=1);

namespace SortingPhotosByDate\Tests\Unit\Domain\ValueObjects;

use PHPUnit\Framework\TestCase;
use SortingPhotosByDate\Domain\ValueObjects\FileCategory;

final class FileCategoryTest extends TestCase
{
    public function testImagesCategory(): void
    {
        $category = FileCategory::IMAGES;
        $this->assertEquals('images', $category->value);
    }

    public function testAudioCategory(): void
    {
        $category = FileCategory::AUDIO;
        $this->assertEquals('audio', $category->value);
    }

    public function testVideoCategory(): void
    {
        $category = FileCategory::VIDEO;
        $this->assertEquals('video', $category->value);
    }

    public function testOtherCategory(): void
    {
        $category = FileCategory::OTHER;
        $this->assertEquals('other', $category->value);
    }
}

