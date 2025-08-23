<?php

namespace App\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;
use App\Models\User;

class UserFilter extends Filter
{
    public $component = 'select-filter';

    public function apply(Request $request, $query, $value)
    {
        if (!$value) {
            return $query;
        }
        // Filter tickets by related order's user_id
        return $query->whereHas('order', function ($q) use ($value) {
            $q->where('user_id', $value);
        });
    }

    public function options(Request $request)
    {
        // Only users who have at least one order; smaller, faster dropdown
        return User::query()
            ->whereHas('orders')
            ->orderBy('forenames')
            ->orderBy('surname')
            ->limit(1000)
            ->get()
            ->mapWithKeys(function ($user) {
                return [$user->fullName . ' (' . $user->email . ')' => $user->id];
            })
            ->toArray();
    }
}
