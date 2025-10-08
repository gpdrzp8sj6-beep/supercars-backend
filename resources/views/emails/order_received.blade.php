@php
$companyName = config('app.name');
$email = config('mail.from.address');
$websiteUrl = config('app.frontend_url');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Received - {{ $companyName }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; background-color: #f4f4f4;">
    <div style="max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px;">

        <div style="text-align:center; margin-bottom:24px;">
            <img src="{!! asset('logo-light.png') !!}" alt="{{ $companyName }}" style="height:60px; width: auto; max-width: 200px; margin-bottom:8px;" />
        </div>

        <h1 style="color: #e85c2b; text-align: center; margin-bottom: 20px;">ORDER RECEIVED - PROCESSING PAYMENT</h1>

        <p style="font-size: 16px; margin-bottom: 20px;">
            Hi {!! $user->forenames !!} {!! $user->surname !!},
        </p>

        <p style="font-size: 16px; margin-bottom: 20px;">
            Thank you for your order! We've received your order and are now processing your payment. You'll receive another email once your payment has been confirmed and your ticket numbers have been assigned.
        </p>

        <div style="background: #f0f8ff; border-left: 4px solid #e85c2b; padding: 15px; margin: 20px 0;">
            <h3 style="margin: 0 0 10px 0; color: #e85c2b;">What happens next?</h3>
            <ul style="margin: 0; padding-left: 20px;">
                <li>We're processing your payment securely</li>
                <li>Once confirmed, your ticket numbers will be assigned</li>
                <li>You'll receive a confirmation email with your lucky numbers</li>
                <li>You can track your order status in your account</li>
            </ul>
        </div>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <p><strong>Order number:</strong> <span style="color:#e85c2b; font-weight:bold;">#{!! $order->id !!}</span><br>
        <strong>Ordered on:</strong> {!! $order->created_at->format('j M Y \a\t H:i') !!}<br>
        <strong>Status:</strong> <span style="color: #ff8c00; font-weight: bold;">Payment Processing</span></p>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <h2 style="color: #333; margin-bottom: 15px;">Order Summary</h2>

        @if($order->cart && is_array($order->cart))
            @foreach($order->cart as $cartItem)
            @php
            $giveaway = \App\Models\Giveaway::find($cartItem['id']);
            $quantity = $cartItem['amount'];
            @endphp
            
            @if($giveaway)
            <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin: 10px 0; background: #f9f9f9;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    @if($giveaway->images && is_array($giveaway->images) && !empty($giveaway->images[0]))
                    <img src="{!! asset('storage/' . $giveaway->images[0]) !!}"
                         alt="Giveaway Car"
                         style="width: 100px; height: 70px; object-fit: cover; border-radius: 6px;" />
                    @endif
                    <div>
                        <strong style="color: #333;">{!! $giveaway->title !!}</strong><br>
                        <span style="color: #e85c2b; font-weight: bold;">£{!! number_format($giveaway->price, 2) !!}</span>
                    </div>
                </div>
            </div>

            <p><strong>Quantity:</strong> {!! $quantity !!}<br>
            <strong>Draw date:</strong> {!! $giveaway->closes_at->format('l j F Y \a\t H:i') !!}</p>
            @endif

            @endforeach
        @else
            <p style="color: #999; font-style: italic;">Order details will be updated once payment is processed.</p>
        @endif

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <h2 style="color: #333; margin-bottom: 15px;">Payment Summary</h2>

        <div style="border: 1px solid #ddd; border-radius: 8px; overflow: hidden; margin: 15px 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="background: #f8f8f8;">
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; font-weight: bold;">Subtotal</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right;">£{!! number_format($order->original_total, 2) !!}</td>
                </tr>
                @if($order->credit_used > 0)
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; color: #e85c2b;">Credit Applied</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right; color: #e85c2b;">-£{!! number_format($order->credit_used, 2) !!}</td>
                </tr>
                @endif
                <tr style="background: #e85c2b; color: white;">
                    <td style="padding: 15px; font-weight: bold; font-size: 18px;">Total</td>
                    <td style="padding: 15px; text-align: right; font-weight: bold; font-size: 18px;">£{!! number_format($order->total, 2) !!}</td>
                </tr>
            </table>
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{!! $websiteUrl !!}/profile/orders" style="display: inline-block; background: #e85c2b; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;">View Order Status</a>
        </div>

        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">

        <div style="text-align: center; font-size: 14px; color: #666;">
            <p><strong>Need help?</strong></p>
            <p>Contact us at <a href="mailto:{!! $email !!}" style="color: #e85c2b;">{!! $email !!}</a></p>
            <p>Visit our website: <a href="{!! $websiteUrl !!}" style="color: #e85c2b;">{!! $websiteUrl !!}</a></p>
        </div>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #999; text-align: center;">
            <p>&copy; {{ date('Y') }} {{ $companyName }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>