<?php

namespace App\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;
use App\Models\Order;

class OrderFilter extends Filter
{
    public $component = 'select-filter';

    public function apply(Request $request, $query, $value)
    {
        if (!$value) {
            return $query;
        }
        return $query->where('order_id', $value);
    }

    public function options(Request $request)
    {
        return Order::query()
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->pluck('id', 'id')
            ->toArray();
    }
}
