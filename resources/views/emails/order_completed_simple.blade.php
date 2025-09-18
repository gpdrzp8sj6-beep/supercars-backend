 <!-- Legal Footer -->
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
    <title>Order Confirmation - {{ $companyName }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; background-color: #f4f4f4;">
    <div style="max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px;">

        <div style="text-align:center; margin-bottom:24px;">
            <img src="{!! asset('logo-light.png') !!}" alt="{{ $companyName }}" style="height:60px; width: auto; max-width: 200px; margin-bottom:8px;" />
        </div>

        <h1 style="color: #e85c2b; text-align: center; margin-bottom: 20px;">YOUR LUCKY TICKET NUMBERS ARE LOCKED IN!</h1>

        <p style="font-size: 16px; margin-bottom: 20px;">
            Hi {!! $user->forenames !!} {!! $user->surname !!}, Thank you for your entry, you can find a full breakdown of your order below. Make sure you're following us on socials to stay up to date with the live draw; you could be the next big winner!
        </p>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <p><strong>Order number:</strong> <span style="color:#e85c2b; font-weight:bold;">#{!! $order->id !!}</span><br>
        <strong>Ordered on:</strong> {!! $order->created_at->format('j M Y \a\t H:i') !!}</p>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <h2 style="color: #333; margin-bottom: 15px;">Live Draw entries</h2>

        @php
        $giveaway = $order->giveaways->first();
        $numbers = $giveaway ? json_decode($giveaway->pivot->numbers ?? '[]', true) : [];
        $quantity = count($numbers);
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
                    <span style="color: #666; font-size: 14px;">{!! Illuminate\Mail\Markdown::parse($giveaway->description ?? '') !!}</span><br>
                    <span style="color: #e85c2b; font-weight: bold;">£{!! number_format($giveaway->price, 2) !!}</span>
                </div>
            </div>
        </div>
        @endif

        <p><strong>Quantity:</strong> {!! $quantity !!}<br>
        <strong>Draw numbers:</strong> {!! implode(', ', $numbers) !!}<br>
        <strong>Draw date:</strong> {!! $giveaway ? $giveaway->closes_at->format('l j F Y \a\t H:i') : 'TBD' !!}</p>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <h2 style="color: #333; margin-bottom: 15px;">Payment Summary</h2>

        <div style="border: 1px solid #ddd; border-radius: 8px; overflow: hidden; margin: 15px 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="background: #f8f8f8;">
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; font-weight: bold;">Subtotal</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right;">£{!! number_format($order->total, 2) !!}</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;">Not a messer discount</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right; color: #28a745;">-£0.00</td>
                </tr>
                <tr style="background: #f8f8f8;">
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;">Payment method</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right;">N/A</td>
                </tr>
                <tr style="background: #e85c2b; color: white;">
                    <td style="padding: 12px; font-weight: bold;">Order Total</td>
                    <td style="padding: 12px; text-align: right; font-weight: bold;">£{!! number_format($order->total, 2) !!}</td>
                </tr>
            </table>
        </div>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <p style="text-align: center; font-weight: bold; margin-bottom: 20px;">Thank you for shopping with us!</p>

        <div style="text-align:center; margin-top:24px;">
            <img src="{!! asset('logo-light.png') !!}" alt="{!! $companyName !!}" style="height:42px; width: auto; max-width: 150px; margin-bottom:8px;" />
        </div>

        
        <div style="text-align: center; font-size:12px; color:#888; margin-top: 20px;">
            © {!! $companyName !!}<br>
            <a href="{!! $websiteUrl !!}" style="color:#e85c2b;">{!! $websiteUrl !!}</a>
        </div>
    </div>
</body>
</html>