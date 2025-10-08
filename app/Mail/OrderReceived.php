<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderReceived extends Mailable
{
    use Queueable, SerializesModels;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order->fresh(['user']);
    }

    public function build()
    {
        return $this
            ->subject('Order Received - We\'re processing your payment')
            ->view('emails.order_received')
            ->with([
                'order' => $this->order,
                'user' => $this->order->user,
            ]);
    }
}
