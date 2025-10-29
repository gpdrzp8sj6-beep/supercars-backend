<?php

namespace App\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class MerchantTxStatusFilter extends Filter
{
    public $component = 'select-filter';

    public function apply(Request $request, $query, $value)
    {
        if (!$value) {
            return $query;
        }

        switch ($value) {
            case 'matched_completed':
                // Orders with completed status
                return $query->where('status', 'completed');

            case 'matched_pending':
                // Orders with pending status
                return $query->where('status', 'pending');

            case 'matched_failed':
                // Orders with failed status
                return $query->where('status', 'failed');

            case 'id_mismatch':
                // Orders with cancelled status (approximates ID mismatch)
                return $query->where('status', 'cancelled');

            case 'email_match':
                // Orders with any status except completed/pending/failed/cancelled
                return $query->whereNotIn('status', ['completed', 'pending', 'failed', 'cancelled']);

            case 'email_match_no_tx_id':
                // Orders with any status (this will show all orders)
                return $query;

            case 'unmatched':
                // Orders with failed or cancelled status
                return $query->whereIn('status', ['failed', 'cancelled']);

            default:
                return $query;
        }
    }

    public function options(Request $request)
    {
        return [
            'matched_completed' => 'Matched (Completed)',
            'matched_pending' => 'Matched (Pending)',
            'matched_failed' => 'Matched (Failed)',
            'id_mismatch' => 'ID Mismatch',
            'email_match' => 'Email Match',
            'email_match_no_tx_id' => 'Email Match (No TX ID)',
            'unmatched' => 'Unmatched',
        ];
    }
}