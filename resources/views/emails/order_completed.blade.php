@component('mail::message')
# Order Completed

Hello {{ $user->forenames }},

Your order (ID: {{ $order->id }}) has been completed successfully.

@component('mail::button', ['url' => rtrim(config('app.url'), '/') . '/' ])
Visit Website
@endcomponent

Thank you for your purchase!

Regards,
{{ config('app.name') }}
@endcomponent
