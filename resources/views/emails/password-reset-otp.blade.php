@component('mail::message')

# Password Reset Request - {{ config('app.name') }}

We received a request to reset your password for your {{ config('app.name') }} account. If you didn't make this request, please ignore this email.

## Your Password Reset Code

@component('mail::panel')
# {!! $otp !!}
@endcomponent

**Important:** This code will expire in **{!! $expiresIn !!} minutes** for security reasons. Please use it promptly to reset your password.

If you didn't request a password reset, no changes will be made to your account.

Thanks,<br>
{!! config('app.name') !!} Team

@endcomponent