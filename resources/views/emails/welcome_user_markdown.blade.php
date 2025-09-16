@component('mail::message')
# Hello!

Thank you for registering, {!! $user->forenames !!}.

@component('mail::button', ['url' => rtrim(config('app.frontend_url'), '/') . '/' ])
Visit Website
@endcomponent

Thank you for using our app!

Regards,
{!! config('app.name') !!}
@endcomponent
