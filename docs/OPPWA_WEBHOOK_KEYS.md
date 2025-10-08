# OPPWA Webhook Keys - Environment-Specific Configuration

## Overview

OPPWA provides different webhook keys for test and production environments. The system now automatically uses the correct webhook key based on the current environment.

## Current Configuration

### Test Environment (Current)
```php
'test' => [
    'base_url' => 'https://eu-test.oppwa.com',
    'entity_id' => '8ac7a4c7961768c301961b14272d05ed',
    'bearer_token' => 'OGFjN2E0Yzc5NjE3NjhjMzAxOTYxYjE0MjY1MDA1ZWJ8dz10WFVZcWgjYmN3IyU3azhZWFQ=',
    'webhook_key' => '92C4E99ED46AADE51D7C3C95348F8D6C7822C21212FFC0DB584B36BF1B5258DC',
    'webhook_endpoint' => 'https://api.jonnyfromdonnycompetitions.co.uk/v1/oppwa/webhook',
],
```

### Production Environment (Ready for setup)
```php
'production' => [
    'base_url' => 'https://eu-prod.oppwa.com',
    'entity_id' => '8ac9a4cd9662a1bc0196687d626128ad',
    'bearer_token' => 'OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY=',
    'webhook_key' => env('OPPWA_PROD_WEBHOOK_KEY', ''), // Will be set when you get production webhook key
    'webhook_endpoint' => 'https://api.jonnyfromdonnycompetitions.co.uk/v1/oppwa/webhook',
],
```

## How It Works

### Automatic Environment Detection

The system automatically uses the correct webhook key:

```php
// In PaymentController.php
private function getWebhookKey(): string
{
    $environment = config('oppwa.environment', 'test');
    $envKey = $environment === 'prod' ? 'production' : 'test';
    
    return config("oppwa.{$envKey}.webhook_key");
}
```

### Environment Switching

**For Test Environment:**
```bash
# .env
OPPWA_ENVIRONMENT=test
```
- Uses test webhook key: `92C4E99ED46AADE51D7C3C95348F8D6C7822C21212FFC0DB584B36BF1B5258DC`
- Webhooks are decrypted using test key
- API calls use test credentials

**For Production Environment:**
```bash
# .env
OPPWA_ENVIRONMENT=prod
OPPWA_PROD_WEBHOOK_KEY=your_production_webhook_key_here
```
- Uses production webhook key from environment variable
- Webhooks are decrypted using production key
- API calls use production credentials

## When You Get Production Webhook Key

When OPPWA provides the production webhook configuration:

1. **Update `.env` file:**
   ```bash
   OPPWA_ENVIRONMENT=prod
   OPPWA_PROD_WEBHOOK_KEY=your_production_webhook_key_from_oppwa
   ```

2. **Test the switch:**
   ```bash
   # Switch back to test
   OPPWA_ENVIRONMENT=test
   
   # Switch to production
   OPPWA_ENVIRONMENT=prod
   ```

3. **Verify webhook processing:**
   - Test webhooks should work with test key
   - Production webhooks should work with production key

## Environment Variables

### Required for Test (Current)
```bash
OPPWA_ENVIRONMENT=test
# No additional variables needed - test webhook key is in config
```

### Required for Production (Future)
```bash
OPPWA_ENVIRONMENT=prod
OPPWA_PROD_WEBHOOK_KEY=your_production_webhook_key
```

## Security Benefits

### ✅ Environment Isolation
- Test and production webhook keys are completely separate
- No risk of cross-environment contamination
- Clear security boundaries

### ✅ Easy Switching
- Single environment variable controls everything
- No manual credential changes needed
- Automatic key selection

### ✅ Future-Ready
- Production setup is ready
- Just add the production webhook key when available
- No code changes needed

## Testing

### Current Test Setup
```bash
# Verify current configuration
php artisan tinker

# Check current environment
config('oppwa.environment')
// Should return: "test"

# Check current webhook key
app('App\Http\Controllers\Payment\PaymentController')->getWebhookKey()
// Should return test webhook key
```

### When Moving to Production
```bash
# Set production environment
echo "OPPWA_ENVIRONMENT=prod" >> .env
echo "OPPWA_PROD_WEBHOOK_KEY=your_key_here" >> .env

# Verify production configuration
php artisan config:clear
php artisan tinker

config('oppwa.environment')
// Should return: "prod"
```

## Troubleshooting

### Issue: "Webhook decryption failed"
- **Check**: Current environment matches the webhook source
- **Solution**: Verify `OPPWA_ENVIRONMENT` in `.env`

### Issue: "Empty webhook key"
- **Check**: Production webhook key is set in `.env`
- **Solution**: Add `OPPWA_PROD_WEBHOOK_KEY=...` to `.env`

### Issue: "Wrong webhook key format"
- **Check**: Webhook key is the correct 64-character hex string
- **Solution**: Copy exact key from OPPWA dashboard

## File Changes Summary

### Modified Files
1. **`config/oppwa.php`** - Added webhook keys to environment configs
2. **`app/Http/Controllers/Payment/PaymentController.php`** - Added `getWebhookKey()` method
3. **`.env`** - Removed hardcoded webhook key, added production placeholder
4. **`.env.example`** - Updated with new structure

### New Helper Method
```php
private function getWebhookKey(): string
{
    $environment = config('oppwa.environment', 'test');
    $envKey = $environment === 'prod' ? 'production' : 'test';
    
    return config("oppwa.{$envKey}.webhook_key");
}
```

This ensures the correct webhook key is always used for the current environment! 🔐