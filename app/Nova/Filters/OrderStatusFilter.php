<?php

namespace App\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class OrderStatusFilter extends Filter
{
    public $component = 'select-filter';

    public function apply(Request $request, $query, $value)
    {
        if (!$value) {
            return $query;
        }

        return $query->where('status', $value);
    }

    public function options(Request $request)
    {
        return [
            'completed' => 'Completed',
            'pending' => 'Pending',
            'cancelled' => 'Cancelled',
            'failed' => 'Failed',
        ];
    }
}