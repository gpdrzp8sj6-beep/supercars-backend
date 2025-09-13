<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Confirmation - Dream Car Giveaways</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 0 auto; background: white; }
        .header { background: linear-gradient(135deg, #e85c2b 0%, #d44a1f 100%); padding: 30px 20px; text-align: center; border-radius: 12px 12px 0 0; }
        .header img { height: 50px; margin-bottom: 15px; }
        .header h1 { color: white; font-size: 28px; font-weight: bold; margin: 0; text-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .welcome { background: #f8f9fa; padding: 25px; border-left: 4px solid #e85c2b; }
        .section { background: white; border: 2px solid #e85c2b; border-radius: 12px; padding: 25px; margin: 20px; }
        .section h2 { color: #e85c2b; font-size: 20px; margin-top: 0; margin-bottom: 20px; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; }
        .order-details { display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; padding: 15px; border-radius: 8px; }
        .status { background: #e85c2b; color: white; padding: 8px 16px; border-radius: 20px; font-weight: bold; }
        .giveaway-card { background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid #dee2e6; }
        .giveaway-content { display: flex; align-items: center; gap: 20px; }
        .giveaway-image { width: 120px; height: 80px; object-fit: cover; border-radius: 8px; border: 2px solid #e85c2b; }
        .ticket-section { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 20px; }
        .ticket-numbers { background: white; border: 2px solid #e85c2b; border-radius: 8px; padding: 15px; margin-bottom: 15px; }
        .number-badge { background: #e85c2b; color: white; padding: 6px 12px; border-radius: 20px; font-weight: bold; font-size: 14px; display: inline-block; margin: 2px; }
        .payment-table { width: 100%; border-collapse: collapse; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; }
        .payment-table td { padding: 15px; border-bottom: 1px solid #dee2e6; }
        .footer { background: #343a40; color: white; padding: 30px 20px; text-align: center; border-radius: 12px; margin: 20px; }
        .footer img { height: 40px; margin-bottom: 15px; filter: brightness(0) invert(1); }
        .legal { text-align: center; margin: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; }
        .legal a { color: #e85c2b; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Section -->
        <div class="header">
            <img src="{{ asset('logo.svg') }}" alt="Dream Car Giveaways" />
            <h1>🎉 YOUR LUCKY TICKET NUMBERS ARE LOCKED IN! 🎉</h1>
        </div>

        <!-- Welcome Message -->
        <div class="welcome">
            <p>Hi <strong>{{ $user->forenames }} {{ $user->surname }}</strong>,<br><br>
            Thank you for your entry! You can find a full breakdown of your order below. Make sure you're following us on socials to stay up to date with the live draw; you could be the next big winner!</p>
        </div>

        <!-- Order Details -->
        <div class="section">
            <h2>📋 Order Details</h2>
            <div class="order-details">
                <div>
                    <strong style="color: #e85c2b;">Order #{{ $order->id }}</strong><br>
                    <span style="color: #666; font-size: 14px;">Ordered on {{ $order->created_at->format('j M Y \a\t H:i') }}</span>
                </div>
                <div class="status">✓ COMPLETED</div>
            </div>
        </div>

        <!-- Live Draw Entries -->
        <div style="background: white; border: 2px solid #28a745; border-radius: 12px; padding: 25px; margin: 20px;">
            <h2 style="color: #28a745; font-size: 20px; margin-top: 0; margin-bottom: 20px; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px;">🎯 Live Draw Entries</h2>

            @php
            $giveaway = $order->giveaways->first();
            $numbers = $giveaway ? json_decode($giveaway->pivot->numbers ?? '[]', true) : [];
            $quantity = count($numbers);
            @endphp

            @if($giveaway)
            <div class="giveaway-card">
                <div class="giveaway-content">
                    <img src="@if($giveaway->images && is_array($giveaway->images) && isset($giveaway->images[0]) && str_starts_with($giveaway->images[0] ?? '', 'http')){{ 'storage/' . $giveaway->images[0] }}@elseif($giveaway->images && is_array($giveaway->images) && isset($giveaway->images[0])){{ asset('storage/' . $giveaway->images[0]) }}@else{{ asset('logo.svg') }}@endif" alt="Giveaway Car" class="giveaway-image" />
                    <div>
                        <h3 style="margin: 0 0 8px 0; color: #333; font-size: 18px;">🏆 Win this {{ $giveaway->title }}</h3>
                        <p style="margin: 0 0 8px 0; color: #666; font-size: 14px;">{{ $giveaway->description ?? '' }}</p>
                        <p style="margin: 0; color: #e85c2b; font-weight: bold; font-size: 16px;">£{{ number_format($giveaway->price, 2) }}</p>
                    </div>
                </div>
            </div>
            @endif

            <!-- Ticket Details -->
            <div class="ticket-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h4 style="margin: 0; color: #856404; font-size: 16px;">🎫 Your Ticket Numbers</h4>
                    <span style="background: #e85c2b; color: white; padding: 6px 12px; border-radius: 15px; font-weight: bold;">Qty: {{ $quantity }}</span>
                </div>

                <div class="ticket-numbers">
                    <p style="margin: 0 0 10px 0; font-weight: bold; color: #333;">Draw Numbers:</p>
                    <div>
                        @foreach($numbers as $number)
                        <span class="number-badge">{{ $number }}</span>
                        @endforeach
                    </div>
                </div>

                <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; padding: 15px; margin-top: 15px;">
                    <p style="margin: 0; color: #155724; font-weight: bold;">📅 Draw Date: {{ $giveaway ? $giveaway->closes_at->format('l j F Y \a\t H:i') : 'TBD' }}</p>
                </div>
            </div>
        </div>

        <!-- Payment Section -->
        <div style="background: white; border: 2px solid #007bff; border-radius: 12px; padding: 25px; margin: 20px;">
            <h2 style="color: #007bff; font-size: 20px; margin-top: 0; margin-bottom: 20px; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px;">💳 Payment Summary</h2>

            <table class="payment-table">
                <tr style="background-color: #f8f9fa;">
                    <td style="font-weight: bold;">Subtotal</td>
                    <td style="text-align: right; font-weight: bold;">£{{ number_format($order->total, 2) }}</td>
                </tr>
                <tr style="background-color: #d4edda;">
                    <td>Dream Points discount</td>
                    <td style="text-align: right; color: #28a745; font-weight: bold;">-£0.00</td>
                </tr>
                <tr style="background-color: #f8f9fa;">
                    <td>Payment method</td>
                    <td style="text-align: right;">N/A</td>
                </tr>
                <tr style="background: linear-gradient(135deg, #e85c2b 0%, #d44a1f 100%); color: white;">
                    <td style="font-weight: bold; font-size: 18px;">Order Total</td>
                    <td style="text-align: right; font-weight: bold; font-size: 18px;">£{{ number_format($order->total, 2) }}</td>
                </tr>
            </table>
        </div>

        <!-- Footer -->
        <div class="footer">
            <img src="{{ asset('logo.svg') }}" alt="Dream Car Giveaways" />
            <p style="margin: 0 0 20px 0; font-size: 16px; font-weight: bold;">Thank you for shopping with us!</p>
            <p style="margin: 0; font-size: 14px; color: #adb5bd;">Stay tuned for the live draw and follow us on social media for updates.</p>
        </div>

        <!-- Legal Footer -->
        <div class="legal">
            <p style="margin: 0; font-size: 12px; color: #6c757d; line-height: 1.5;">
                © Dream Car Giveaways<br>
                Registered Company Number: <a href="https://find-and-update.company-information.service.gov.uk/company/11320154">11320154</a> England and Wales<br>
                <a href="https://dreamcargiveaways.co.uk">dreamcargiveaways.co.uk</a>
            </p>
        </div>
    </div>
</body>
</html>