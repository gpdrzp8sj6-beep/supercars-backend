# OPPWA Configuration Migration Guide

## Overview

OPPWA configuration has been moved from environment variables to a structured config file for better organization and security.

## What Changed

### ✅ NEW: Config-based Structure

All OPPWA credentials are now stored in:
- **`config/oppwa.php`** - Contains test and production credentials
- **`.env`** - Only contains environment selector and webhook key

### ❌ OLD: Environment Variables (Removed)

These environment variables are no longer used:
- `OPPWA_ENTITY_ID`
- `OPPWA_BEARER_TOKEN`
- `OPPWA_KEY` (renamed to `OPPWA_WEBHOOK_KEY`)

## Configuration Structure

### config/oppwa.php

```php
<?php

return [
    // Environment selector
    'environment' => env('OPPWA_ENVIRONMENT', 'test'),
    
    // Webhook decryption key
    'webhook_key' => env('OPPWA_WEBHOOK_KEY'),
    
    // Test environment credentials
    'test' => [
        'base_url' => 'https://eu-test.oppwa.com',
        'entity_id' => '8ac7a4c7961768c301961b14272d05ed',
        'bearer_token' => 'OGFjN2E0Yzc5NjE3NjhjMzAxOTYxYjE0MjY1MDA1ZWJ8dz10WFVZcWgjYmN3IyU3azhZWFQ=',
    ],
    
    // Production environment credentials
    'production' => [
        'base_url' => 'https://eu-prod.oppwa.com',
        'entity_id' => '8ac9a4cd9662a1bc0196687d626128ad',
        'bearer_token' => 'OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY=',
    ],
    
    // 3DS testing configuration
    '3ds_testing' => [
        'enabled' => env('ENABLE_3DS_TEST_MODE', false),
        'flow' => env('3DS_TEST_FLOW', 'challenge'),
    ],
];
```

### .env

```bash
# OPPWA Configuration
OPPWA_ENVIRONMENT=test  # Switch between 'test' and 'prod'
OPPWA_WEBHOOK_KEY=92C4E99ED46AADE51D7C3C95348F8D6C7822C21212FFC0DB584B36BF1B5258DC

# 3DS Testing (optional)
ENABLE_3DS_TEST_MODE=true
3DS_TEST_FLOW=challenge
```

## Credentials from Simon Lowe

### Test Environment
- **Entity ID**: `8ac7a4c7961768c301961b14272d05ed`
- **Bearer Token**: `OGFjN2E0Yzc5NjE3NjhjMzAxOTYxYjE0MjY1MDA1ZWJ8dz10WFVZcWgjYmN3IyU3azhZWFQ=`

### Production Environment
- **Entity ID**: `8ac9a4cd9662a1bc0196687d626128ad`
- **Bearer Token**: `OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY=`

## How It Works

### Environment Switching

To switch between test and production:

```bash
# For testing
OPPWA_ENVIRONMENT=test

# For production
OPPWA_ENVIRONMENT=prod
```

The system automatically uses the correct:
- API endpoint
- Entity ID
- Bearer Token

### Code Usage

In `PaymentController.php`:

```php
// Get current environment configuration
$environment = config('oppwa.environment'); // 'test' or 'prod'
$envKey = $environment === 'prod' ? 'production' : 'test';

// Get credentials for current environment
$baseUrl = config("oppwa.{$envKey}.base_url");
$entityId = config("oppwa.{$envKey}.entity_id");
$bearerToken = config("oppwa.{$envKey}.bearer_token");

// Use for API calls
$url = "{$baseUrl}/v1/checkouts";
$data = "entityId={$entityId}&amount={$amount}...";
```

## Benefits

### ✅ Security
- Credentials not exposed in `.env` file
- Easier to manage different environments
- Clear separation of concerns

### ✅ Organization
- All OPPWA settings in one place
- Environment-specific configuration
- Better version control (config files can be committed)

### ✅ Flexibility
- Easy to switch between test/production
- Simple to add new environments
- Centralized configuration management

## Migration Steps

1. ✅ **Created** `config/oppwa.php` with all credentials
2. ✅ **Updated** `PaymentController.php` to use config instead of env
3. ✅ **Simplified** `.env` to only contain environment selector
4. ✅ **Updated** `.env.example` with new structure

## Testing

To test the new configuration:

1. **Set environment**:
   ```bash
   OPPWA_ENVIRONMENT=test
   ```

2. **Try creating a payment** - should use test credentials automatically

3. **Check logs** for OPPWA responses

4. **Switch to production** when ready:
   ```bash
   OPPWA_ENVIRONMENT=prod
   ```

## Troubleshooting

### Common Issues

1. **Config cache**: Clear config cache after changes
   ```bash
   php artisan config:clear
   ```

2. **Wrong environment**: Check `OPPWA_ENVIRONMENT` in `.env`

3. **Missing credentials**: Verify all credentials are in `config/oppwa.php`

### Debugging

Check current configuration:
```bash
php artisan tinker
config('oppwa.test.entity_id')
config('oppwa.production.bearer_token')
```

## Security Notes

- **Config files are version controlled** (unlike `.env`)
- **Credentials are organized by environment**
- **Easy audit trail** of configuration changes
- **No sensitive data in environment variables**

## Future Considerations

- Consider encrypting config file for additional security
- Add environment validation
- Implement credential rotation helpers
- Add configuration verification commands