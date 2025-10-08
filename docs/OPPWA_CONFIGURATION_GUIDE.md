# OPPWA Configuration Guide

## Problem: "Invalid or missing entity type" Error

This error occurs when there's a mismatch between:
- The API endpoint (test vs production)
- The Entity ID (test vs production)  
- The Bearer Token (test vs production)

## Understanding OPPWA Environments

OPPWA has **TWO separate environments**, each with its own credentials:

### 1. TEST Environment (for testing)
- **API Endpoint**: `https://eu-test.oppwa.com`
- **Entity ID**: Get from OPPWA test dashboard
- **Bearer Token**: Get from OPPWA test dashboard
- **Webhook Secret Key**: For test webhooks
- **NDC shows**: `uat01-vm-tx*` or similar

### 2. PRODUCTION Environment (for live payments)
- **API Endpoint**: `https://eu-prod.oppwa.com`
- **Entity ID**: Get from OPPWA production dashboard
- **Bearer Token**: Get from OPPWA production dashboard
- **Webhook Secret Key**: For production webhooks
- **NDC shows**: `prod01-vm-tx*` or similar

## Your Current Issue

The error message shows:
```
ndc: 966293AA4DAD9108EC614A9004E1477E.uat01-vm-tx04
```

The `uat01-vm-tx04` indicates you're hitting the **TEST environment**, but your Entity ID or Bearer Token might be from **PRODUCTION** (or invalid for test).

## Solution

You need to get the correct TEST credentials from OPPWA:

1. **Login to OPPWA Test Dashboard**
2. **Find your TEST Entity ID** (usually starts with `8ac...`)
3. **Find your TEST Bearer Token** (Base64 encoded string)
4. **Update your `.env` file**:

```bash
# Use TEST environment
OPPWA_ENVIRONMENT=test

# TEST Entity ID (get from OPPWA test dashboard)
OPPWA_ENTITY_ID=YOUR_TEST_ENTITY_ID_HERE

# TEST Bearer Token (get from OPPWA test dashboard)
OPPWA_BEARER_TOKEN=YOUR_TEST_BEARER_TOKEN_HERE

# TEST Webhook Secret (you already have this)
OPPWA_KEY=92C4E99ED46AADE51D7C3C95348F8D6C7822C21212FFC0DB584B36BF1B5258DC
```

## Where to Find OPPWA Credentials

### In OPPWA Test Dashboard:
1. Go to: https://test.oppwa.com (or your test dashboard URL)
2. Navigate to **Administration** → **Account Data**
3. Look for:
   - **Entity ID** (Channel settings)
   - **Access Token** / **Bearer Token** (API credentials)
   
### Test Cards for OPPWA
Once configured, you can use test cards like:
- **Visa**: 4200 0000 0000 0000
- **Mastercard**: 5500 0000 0000 0004
- **Success 3DS**: 4012 0010 3881 4193

## Webhook Configuration

From your earlier message, OPPWA provided:
- **Environment**: Test
- **Endpoint**: https://api.jonnyfromdonnycompetitions.co.uk/v1/oppwa/webhook
- **Secret Key**: 92C4E99ED46AADE51D7C3C95348F8D6C7822C21212FFC0DB584B36BF1B5258DC

This is correctly configured in your `.env` file.

## Current Configuration Status

❌ **Entity ID**: May be wrong (current: `8ac7a4c7961768c301961b14272d05ed`)
❌ **Bearer Token**: May be wrong (current: `OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY=`)
✅ **Webhook Secret**: Correct (`92C4E99ED46AADE51D7C3C95348F8D6C7822C21212FFC0DB584B36BF1B5258DC`)
✅ **Environment**: Correct (`test`)

## Action Required

**Contact OPPWA Support** or check your test dashboard to get:
1. The correct **TEST Entity ID**
2. The correct **TEST Bearer Token**

Then update these in your `.env` file.

## Testing After Configuration

1. Update `.env` with correct credentials
2. Try creating a payment checkout
3. Check logs: `tail -f storage/logs/laravel.log`
4. Look for "OPPWA checkout response" log entry
5. Verify no "entity type" errors

## Moving to Production Later

When ready for production:
```bash
OPPWA_ENVIRONMENT=prod
OPPWA_ENTITY_ID=YOUR_PROD_ENTITY_ID
OPPWA_BEARER_TOKEN=YOUR_PROD_BEARER_TOKEN
OPPWA_KEY=YOUR_PROD_WEBHOOK_SECRET
```
