# Psalm Configuration for PHP 8.4

## Current Status

Psalm is temporarily disabled in GrumPHP due to PHP 8.4 compatibility issues with vendor dependencies (`amphp/amp`). The error occurs in vendor code during autoloader initialization, not in our application code.

## Manual Execution

To run Psalm manually with PHP 8.4 compatibility:

```bash
# Option 1: Use the wrapper script (suppresses deprecated warnings)
./bin/psalm-wrapper --no-cache

# Option 2: Use PHP with error_reporting flag
php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_STRICT" ./vendor/bin/psalm --no-cache

# Option 3: Set environment variable
PHP_ERROR_REPORTING="E_ALL & ~E_DEPRECATED & ~E_STRICT" ./vendor/bin/psalm --no-cache
```

## Configuration

The `psalm.xml` file is configured with:
- Error level: 1 (basic checks)
- Project files: `src/` directory only
- Ignored directories: `vendor/`, `tests/`, `bin/`, `config/`, `var/`
- MissingConstructor suppressed for Value Objects

## Future Fix

Once vendor dependencies are updated to support PHP 8.4, Psalm can be re-enabled in `grumphp.yml` by uncommenting the psalm task configuration.

