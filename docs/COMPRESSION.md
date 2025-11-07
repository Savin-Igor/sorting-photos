# File Compression Configuration

## Overview

The application supports optional file compression for images and videos before uploading to Google Photos. Compression can be enabled/disabled and configured via environment variables.

## Environment Variables

Add these variables to your `.env` file:

```bash
# Enable/disable compression
COMPRESSION_ENABLED=false

# Image compression limits (in pixels)
COMPRESSION_JPEG_MAX_PIXELS=75000000   # 75 MP (Google Photos limit)
COMPRESSION_PNG_MAX_PIXELS=200000000    # 200 MP (Google Photos limit)

# Video compression limit (in bytes)
COMPRESSION_VIDEO_MAX_SIZE_BYTES=10737418240  # 10 GB (Google Photos limit)
```

## Default Values

- **COMPRESSION_ENABLED**: `false` (compression disabled by default)
- **COMPRESSION_JPEG_MAX_PIXELS**: `75000000` (75 megapixels)
- **COMPRESSION_PNG_MAX_PIXELS**: `200000000` (200 megapixels)
- **COMPRESSION_VIDEO_MAX_SIZE_BYTES**: `10737418240` (10 GB)

## How It Works

### Image Compression

- **JPEG/JPG**: Compressed if resolution exceeds 75 MP (Google Photos limit)
- **PNG**: Compressed if resolution exceeds 200 MP (Google Photos limit)
- **Other formats**: GIF, WebP are also supported
- Compression preserves aspect ratio and quality (95% for JPEG, 9 compression level for PNG)
- Transparency is preserved for PNG files

### Video Compression

- Videos larger than 10 GB are compressed using FFmpeg
- Uses H.264 codec with CRF 18 (high quality, minimal loss)
- Requires FFmpeg to be installed in the system
- If FFmpeg is not available, original file is returned

## Testing Compression Locally

Use the `test:compression` command to test compression without uploading to Google Photos:

```bash
# Basic usage
make test-compression SOURCE=/path/to/source DEST=/path/to/destination

# With recursive processing
make test-compression SOURCE=/path/to/source DEST=/path/to/destination RECURSIVE=true

# Dry run (show what would be done without actually copying)
make test-compression SOURCE=/path/to/source DEST=/path/to/destination DRY_RUN=true
```

Or directly:

```bash
docker compose exec app php bin/console test:compression /source/path /dest/path [--recursive] [--dry-run]
```

## Example Output

The command will show:
- Files processed
- Original and compressed sizes
- Space saved
- Compression ratio
- Statistics summary

## Notes

- Compression is performed in memory/temp files
- Original files are never modified
- Compressed files are saved to destination directory
- If compression is disabled, files are copied as-is
- Temporary compressed files are automatically cleaned up

