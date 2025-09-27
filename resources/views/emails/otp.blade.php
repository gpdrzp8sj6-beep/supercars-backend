@component('mail::message')

# Welcome to {{ config('app.name') }}!

Your account has been created successfully. To complete your registration please verify your email address using the verification code below.

## Your Verification Code

@component('mail::panel')
# {!! $otp !!}
@endcomponent

**Important:** This code will expire in **{!! $expiresIn !!} minutes** for security reasons. Please use it promptly to complete your account verification.

Thanks for joining us!<br>
{!! config('app.name') !!} Team

@endcomponent
