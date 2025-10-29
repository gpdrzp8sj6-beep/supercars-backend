<?php

namespace Supercars\RecentOrders;

use Laravel\Nova\Card;
use App\Models\Order;

class RecentOrders extends Card
{
    /**
     * The width of the card (1/3, 1/2, or full).
     *
     * @var string
     */
    public $width = '1/2';

    /**
     * Get the component name for the element.
     */
    public function component(): string
    {
        return 'recent-orders';
    }

    /**
     * Prepare the card for JSON serialization.
     */
    public function jsonSerialize(): array
    {
        $recentOrders = Order::where('status', 'completed')->latest()->take(5)->get(['id', 'status', 'total', 'created_at']);

        return array_merge(parent::jsonSerialize(), [
            'recentOrders' => $recentOrders,
        ]);
    }
}
