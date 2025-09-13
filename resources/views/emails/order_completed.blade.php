
@component<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Confirmation - Dream Car Giveaways</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; background-color: #f4f4f4;">
    <div style="max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px;">

        <div style="text-align:center; margin-bottom:24px;">
            <img src="{{ asset('logo.svg') }}" alt="Dream Car Giveaways" style="height:40px; margin-bottom:8px;" />
        </div>

        <h1 style="color: #e85c2b; text-align: center; margin-bottom: 20px;">YOUR LUCKY TICKET NUMBERS ARE LOCKED IN!</h1>

        <p style="font-size: 16px; margin-bottom: 20px;">
            Hi {{ $user->forenames }} {{ $user->surname }}, Thank you for your entry, you can find a full breakdown of your order below. Make sure you're following us on socials to stay up to date with the live draw; you could be the next big winner!
        </p>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <p><strong>Order number:</strong> <span style="color:#e85c2b; font-weight:bold;">#{{ $order->id }}</span><br>
        <strong>Ordered on:</strong> {{ $order->created_at->format('j M Y \a\t H:i') }}</p>

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
                <img src="@if($giveaway->images && is_array($giveaway->images) && !empty($giveaway->images[0])){{ asset('storage/' . $giveaway->images[0]) }}@else{{ asset('logo.svg') }}@endif"
                     alt="Giveaway Car"
                     style="width: 100px; height: 70px; object-fit: cover; border-radius: 6px;" />
                <div>
                    <strong style="color: #333;">{{ $giveaway->title }}</strong><br>
                    <span style="color: #666; font-size: 14px;">{{ $giveaway->description ?? '' }}</span><br>
                    <span style="color: #e85c2b; font-weight: bold;">£{{ number_format($giveaway->price, 2) }}</span>
                </div>
            </div>
        </div>
        @endif

        <p><strong>Quantity:</strong> {{ $quantity }}<br>
        <strong>Draw numbers:</strong> {{ implode(', ', $numbers) }}<br>
        <strong>Draw date:</strong> {{ $giveaway ? $giveaway->closes_at->format('l j F Y \a\t H:i') : 'TBD' }}</p>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <h2 style="color: #333; margin-bottom: 15px;">Payment Summary</h2>

        <div style="border: 1px solid #ddd; border-radius: 8px; overflow: hidden; margin: 15px 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="background: #f8f8f8;">
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; font-weight: bold;">Subtotal</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right;">£{{ number_format($order->total, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;">Dream Points discount</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right; color: #28a745;">-£0.00</td>
                </tr>
                <tr style="background: #f8f8f8;">
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;">Payment method</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right;">N/A</td>
                </tr>
                <tr style="background: #e85c2b; color: white;">
                    <td style="padding: 12px; font-weight: bold;">Order Total</td>
                    <td style="padding: 12px; text-align: right; font-weight: bold;">£{{ number_format($order->total, 2) }}</td>
                </tr>
            </table>
        </div>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <p style="text-align: center; font-weight: bold; margin-bottom: 20px;">Thank you for shopping with us!</p>

        <div style="text-align:center; margin-top:24px;">
            <img src="{{ asset('logo.svg') }}" alt="Dream Car Giveaways" style="height:32px; margin-bottom:8px;" />
        </div>

        <div style="text-align: center; font-size:12px; color:#888; margin-top: 20px;">
            © Dream Car Giveaways<br>
            Registered Company Number: <a href="https://find-and-update.company-information.service.gov.uk/company/11320154" style="color:#e85c2b;">11320154</a> England and Wales<br>
            <a href="https://dreamcargiveaways.co.uk" style="color:#e85c2b;">dreamcargiveaways.co.uk</a>
        </div>
    </div>
</body>
</html>ail::message')

<!-- Header Section -->
<div style="background: linear-gradient(135deg, #e85c2b 0%, #d44a1f 100%); padding: 30px 20px; text-align: center; border-radius: 12px 12px 0 0; margin-bottom: 30px;">
    <img src="{{ asset('logo.svg') }}" alt="Dream Car Giveaways" style="height: 50px; margin-bottom: 15px;" />
    <h1 style="color: white; font-size: 28px; font-weight: bold; margin: 0; text-shadow: 0 2px 4px rgba(0,0,0,0.3);">
        🎉 YOUR LUCKY TICKET NUMBERS ARE LOCKED IN! 🎉
    </h1>
</div>

# YOUR LUCKY TICKET NUMBERS ARE LOCKED IN!

Hi {{ $user->forenames }} {{ $user->surname }}, Thank you for your entry, you can find a full breakdown of your order below. Make sure you’re following us on socials to stay up to date with the live draw; you could be the next big winner!

<!-- Order Details -->
<div style="background: white; border: 2px solid #e85c2b; border-radius: 12px; padding: 25px; margin-bottom: 30px;">
    <h2 style="color: #e85c2b; font-size: 20px; margin-top: 0; margin-bottom: 20px; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px;">
        📋 Order Details
    </h2>
    <div style="display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; padding: 15px; border-radius: 8px;">
        <div>
            <strong style="color: #e85c2b;">Order #{{ $order->id }}</strong><br>
            <span style="color: #666; font-size: 14px;">Ordered on {{ $order->created_at->format('j M Y \a\t H:i') }}</span>
        </div>
        <div style="text-align: right;">
            <span style="background: #e85c2b; color: white; padding: 8px 16px; border-radius: 20px; font-weight: bold;">
                ✓ COMPLETED
            </span>
        </div>
    </div>
</div>

<!-- Live Draw Entries -->
<div style="background: white; border: 2px solid #28a745; border-radius: 12px; padding: 25px; margin-bottom: 30px;">
    <h2 style="color: #28a745; font-size: 20px; margin-top: 0; margin-bottom: 20px; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px;">
        🎯 Live Draw Entries
    </h2>

    @php
    $giveaway = $order->giveaways->first();
    $numbers = $giveaway ? json_decode($giveaway->pivot->numbers ?? '[]', true) : [];
    $quantity = count($numbers);
    @endphp

    @if($giveaway)
    <div style="background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid #dee2e6;">
        <div style="display: flex; align-items: center; gap: 20px;">
            <img src="@if($giveaway->images && is_array($giveaway->images) && isset($giveaway->images[0]) && str_starts_with($giveaway->images[0] ?? '', 'http')){{ 'storage/' . $giveaway->images[0] }}@elseif($giveaway->images && is_array($giveaway->images) && isset($giveaway->images[0])){{ asset('storage/' . $giveaway->images[0]) }}@else{{ asset('logo.svg') }}@endif"
                 alt="Giveaway Car"
                 style="width: 120px; height: 80px; object-fit: cover; border-radius: 8px; border: 2px solid #e85c2b;" />
            <div style="flex: 1;">
                <h3 style="margin: 0 0 8px 0; color: #333; font-size: 18px;">
                    🏆 Win this {{ $giveaway->title }}
                </h3>
                <p style="margin: 0 0 8px 0; color: #666; font-size: 14px;">
                    {{ $giveaway->description ?? '' }}
                </p>
                <p style="margin: 0; color: #e85c2b; font-weight: bold; font-size: 16px;">
                    £{{ number_format($giveaway->price, 2) }}
                </p>
            </div>
        </div>
    </div>
    @endif

    <!-- Ticket Details -->
    <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h4 style="margin: 0; color: #856404; font-size: 16px;">🎫 Your Ticket Numbers</h4>
            <span style="background: #e85c2b; color: white; padding: 6px 12px; border-radius: 15px; font-weight: bold;">
                Qty: {{ $quantity }}
            </span>
        </div>

        <div style="background: white; border: 2px solid #e85c2b; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
            <p style="margin: 0 0 10px 0; font-weight: bold; color: #333;">Draw Numbers:</p>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                @foreach($numbers as $number)
                <span style="background: #e85c2b; color: white; padding: 6px 12px; border-radius: 20px; font-weight: bold; font-size: 14px;">
                    {{ $number }}
                </span>
                @endforeach
            </div>
        </div>

        <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; padding: 15px;">
            <p style="margin: 0; color: #155724; font-weight: bold;">
                📅 Draw Date: {{ $giveaway ? $giveaway->closes_at->format('l j F Y \a\t H:i') : 'TBD' }}
            </p>
        </div>
    </div>
</div>

## Payment

<!-- Payment Section -->
<div style="background: white; border: 2px solid #007bff; border-radius: 12px; padding: 25px; margin-bottom: 30px;">
    <h2 style="color: #007bff; font-size: 20px; margin-top: 0; margin-bottom: 20px; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px;">
        💳 Payment Summary
    </h2>

    <table style="width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
        <tr style="background-color: #f8f9fa;">
            <td style="padding: 15px; border-bottom: 1px solid #dee2e6; font-weight: bold;">Subtotal</td>
            <td style="padding: 15px; border-bottom: 1px solid #dee2e6; text-align: right; font-weight: bold;">£{{ number_format($order->total, 2) }}</td>
        </tr>
        <tr style="background-color: #d4edda;">
            <td style="padding: 15px; border-bottom: 1px solid #dee2e6;">Dream Points discount</td>
            <td style="padding: 15px; border-bottom: 1px solid #dee2e6; text-align: right; color: #28a745; font-weight: bold;">-£0.00</td>
        </tr>
        <tr style="background-color: #f8f9fa;">
            <td style="padding: 15px; border-bottom: 1px solid #dee2e6;">Payment method</td>
            <td style="padding: 15px; border-bottom: 1px solid #dee2e6; text-align: right;">N/A</td>
        </tr>
        <tr style="background: linear-gradient(135deg, #e85c2b 0%, #d44a1f 100%); color: white;">
            <td style="padding: 15px; font-weight: bold; font-size: 18px;">Order Total</td>
            <td style="padding: 15px; text-align: right; font-weight: bold; font-size: 18px;">£{{ number_format($order->total, 2) }}</td>
        </tr>
    </table>
</div>

<!-- Footer -->
<div style="background: #343a40; color: white; padding: 30px 20px; text-align: center; border-radius: 12px; margin-top: 30px;">
    <img src="{{ asset('logo.svg') }}" alt="Dream Car Giveaways" style="height: 40px; margin-bottom: 15px; filter: brightness(0) invert(1);" />
    <p style="margin: 0 0 20px 0; font-size: 16px; font-weight: bold;">
        Thank you for shopping with us!
    </p>
    <p style="margin: 0; font-size: 14px; color: #adb5bd;">
        Stay tuned for the live draw and follow us on social media for updates.
    </p>
</div>

<!-- Legal Footer -->
<div style="text-align: center; margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
    <p style="margin: 0; font-size: 12px; color: #6c757d; line-height: 1.5;">
        © Dream Car Giveaways<br>
        Registered Company Number: <a href="https://find-and-update.company-information.service.gov.uk/company/11320154" style="color: #e85c2b; text-decoration: none;">11320154</a> England and Wales<br>
        <a href="https://dreamcargiveaways.co.uk" style="color: #e85c2b; text-decoration: none;">dreamcargiveaways.co.uk</a>
    </p>
</div>

@endcomponent
