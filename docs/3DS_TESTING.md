# 3D Secure Testing Guide

## Overview
This document explains how to test 3D Secure (3DS) authentication with the OPPWA payment gateway during webhook testing.

## Configuration

Add the following environment variables to your `.env` file:

```bash
# Enable 3DS test mode
ENABLE_3DS_TEST_MODE=true

# Set the authentication flow type
3DS_TEST_FLOW=challenge  # Options: 'challenge' or 'frictionless'
```

## Parameters Explanation

### customParameters[3DS2_enrolled]=true
- Enables 3D Secure 2.0 authentication for any test card
- Normally, only specific test cards support 3DS
- This parameter allows testing with any card number

### customParameters[3DS2_flow]=challenge
- Forces the authentication to use a **challenge flow**
- Customer will be redirected to the issuer's authentication page
- Requires user interaction (entering OTP, password, etc.)

### customParameters[3DS2_flow]=frictionless
- Forces the authentication to use a **frictionless flow**
- Authentication happens in the background without user interaction
- Customer won't see any authentication page

### threeDSecure.challengeIndicator=04
- Additional parameter to force challenge flow
- Value `04` = "Challenge requested for this transaction"
- Only used when `3DS_TEST_FLOW=challenge`

## Usage

1. **Enable test mode** in `.env`:
   ```bash
   ENABLE_3DS_TEST_MODE=true
   ```

2. **Choose flow type**:
   - For testing with authentication page: `3DS_TEST_FLOW=challenge`
   - For testing without authentication page: `3DS_TEST_FLOW=frictionless`

3. **Make a payment** - the parameters will be automatically included in the checkout request

4. **Verify webhook** - check that webhooks are received correctly after 3DS authentication

## Disabling Test Mode

When you're done testing, disable the test mode:

```bash
ENABLE_3DS_TEST_MODE=false
```

Or simply remove/comment out the variable from your `.env` file.

## Related Files

- Payment Controller: `app/Http/Controllers/Payment/PaymentController.php`
- Order Model: `app/Models/Order.php` (email sending disabled during testing)

## Notes

- These parameters only work in test/sandbox environments
- In production with real cards, 3DS behavior is determined by the card issuer
- Currently, order completion emails are disabled for webhook testing
