# How to Get OPPWA Bearer Token

## The Problem
You're getting error `800.900.300: invalid authentication information` because the Bearer Token is incorrect.

## What OPPWA Provided You
✅ **Webhook Secret Key**: `92C4E99ED46AADE51D7C3C95348F8D6C7822C21212FFC0DB584B36BF1B5258DC`  
✅ **Entity ID**: `8ac7a4c7961768c301961b14272d05ed` (confirmed from webhook)  
❌ **Bearer Token**: Not provided or incorrect

## Where to Find Bearer Token

### Option 1: OPPWA Test Dashboard (RECOMMENDED)

1. **Login URL**: https://test.oppwa.com/login (or check your onboarding email for the URL)

2. **Navigate to Credentials**:
   ```
   Dashboard → Administration → Account Data
   OR
   Dashboard → Administration → User Management
   OR
   Dashboard → Settings → API Credentials
   ```

3. **Look for**:
   - Section: "REST API Credentials" or "Access Credentials"
   - Field: "User ID" (starts with `8ac...`)
   - Field: "Password" or "Authentication Password"
   - Field: "Bearer Token" or "Access Token" (might be pre-generated)

### Option 2: Check OPPWA Onboarding Email

Search your email for:
- **From**: OPPWA, Peach Payments, or ACI Worldwide
- **Subject keywords**: "credentials", "API access", "test environment"
- **Look for**:
  - User ID
  - Password
  - REST API credentials
  - Bearer Token

### Option 3: Contact OPPWA Support

**Email OPPWA Support** with:
```
Subject: Request for Test Environment Bearer Token

Hi OPPWA Support,

I need the Bearer Token for the test environment API authentication.

Account Details:
- Entity ID: 8ac7a4c7961768c301961b14272d05ed
- Environment: Test
- Webhook endpoint: https://api.jonnyfromdonnycompetitions.co.uk/v1/oppwa/webhook

I have the webhook secret key but need the Bearer Token to create payment checkouts via your REST API.

Thank you!
```

### Option 4: Generate Bearer Token from User ID + Password

If you have User ID and Password:

**Format**: `userId|password`

**Example**:
- User ID: `8ac9a4c9662ab1d0196687d61a22933`
- Password: `MySecretPass123!`
- Combined: `8ac9a4c9662ab1d0196687d61a22933|MySecretPass123!`

**Encode to Base64**:
```bash
echo -n "userId|password" | base64
```

**Result**: This is your Bearer Token

## What You Currently Have

Your current `.env` file has:
```bash
OPPWA_BEARER_TOKEN=OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY=
```

This token is returning `800.900.300` error, which means:
- It's for the wrong environment (production instead of test)
- It's expired or invalid
- It's for a different account

## IMPORTANT: Two Different Keys

Don't confuse these two:

### 1. Webhook Secret Key (You Already Have This ✅)
- **Purpose**: Decrypt webhooks FROM OPPWA
- **Location**: `.env` as `OPPWA_KEY`
- **Value**: `92C4E99ED46AADE51D7C3C95348F8D6C7822C21212FFC0DB584B36BF1B5258DC`
- **Used in**: Webhook handler to decrypt incoming notifications

### 2. Bearer Token (You Need This ❌)
- **Purpose**: Authenticate API requests TO OPPWA
- **Location**: `.env` as `OPPWA_BEARER_TOKEN`
- **Value**: Need to get from OPPWA
- **Used in**: Creating payment checkouts

## Next Steps

1. **Try logging into OPPWA test dashboard** using the credentials from your onboarding email
2. **Find the Bearer Token** in the dashboard under API credentials
3. **Update `.env` file**:
   ```bash
   OPPWA_BEARER_TOKEN=your_correct_bearer_token_here
   ```
4. **Test again** - the error should be resolved

## Still Can't Find It?

If you still can't find it:
1. Check if you have access to OPPWA Merchant Dashboard
2. Ask whoever set up the OPPWA account
3. Contact OPPWA support directly

## Quick Test Command

Once you get the new token, test it:
```bash
# In your Laravel app
php artisan tinker

# Then run:
$token = env('OPPWA_BEARER_TOKEN');
echo base64_decode($token);
// This should show: userId|password format
```

If the decoded format doesn't look like `userId|password`, the token is incorrect.
