<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class TransactionSheet extends Model
{
    protected $fillable = [
        'filename',
        'file_path',
        'giveaway_id',
        'summary',
        'details',
    ];

    protected $casts = [
        'summary' => 'array',
        'details' => 'array',
    ];

    public function giveaway()
    {
        return $this->belongsTo(Giveaway::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transactionSheet) {
            if ($transactionSheet->file_path && !$transactionSheet->filename) {
                $transactionSheet->filename = basename($transactionSheet->file_path);
            }

            if ($transactionSheet->file_path) {
                $transactionSheet->processCsvFile();
            }
        });
    }

    public function processCsvFile()
    {
        try {
            // Use storage_path for local files during testing
            $filePath = storage_path('app/' . $this->file_path);

            if (!file_exists($filePath)) {
                // Try public disk path
                $filePath = Storage::disk('public')->path($this->file_path);
            }

            if (!file_exists($filePath)) {
                Log::error("Transaction sheet file not found: {$filePath}");
                return;
            }

            $csvData = array_map('str_getcsv', file($filePath));
            $headers = array_shift($csvData);

            $transactions = [];
            $totalRevenue = 0;
            $creditUsage = 0;
            $matchedOrders = 0;

            // Get all completed orders for the selected giveaway
            $giveawayOrders = Order::where('status', 'completed')
                ->whereHas('giveaways', function ($query) {
                    $query->where('giveaways.id', $this->giveaway_id);
                })
                ->with(['user', 'giveaways' => function ($query) {
                    $query->where('giveaways.id', $this->giveaway_id)
                          ->withPivot('numbers', 'amount');
                }])
                ->get();

            foreach ($csvData as $row) {
                if (count($row) < count($headers)) continue;

                $transaction = array_combine($headers, $row);

                $transactionData = [
                    'transaction_id' => $transaction['Transaction ID'] ?? null,
                    'auth_code' => $transaction['Auth Code'] ?? null,
                    'amount' => $transaction['Amount'] ?? 0,
                    'last_four_digits' => $transaction['Last Four Digits'] ?? null,
                    'name_surname' => $transaction['Name/Surname'] ?? null,
                    'customer_email' => $transaction['Customer Email'] ?? null,
                    'short_id' => $transaction['Short ID'] ?? null,
                    'merchant_tx_id' => $transaction['Merchant Transaction ID'] ?? null,
                    'currency' => $transaction['Currency'] ?? 'GBP',
                    'status' => $transaction['Status'] ?? 'Unknown',
                    'timestamp' => $transaction['Timestamp'] ?? null,
                ];

                // Try to match this transaction to an order
                $matchedOrder = null;
                $transactionAmount = (float) $transactionData['amount'];

                // PRIMARY MATCH: Check if Merchant Transaction ID matches Order ID
                if (!empty($transactionData['merchant_tx_id'])) {
                    $matchedOrder = $giveawayOrders->first(function ($order) use ($transactionData) {
                        return $order->id == $transactionData['merchant_tx_id'];
                    });

                    if ($matchedOrder) {
                        $transactionData['match_type'] = 'order_id';
                    }
                }

                if ($matchedOrder) {
                    $transactionData['matched_order'] = true;
                    $transactionData['order_id'] = $matchedOrder->id;
                    $transactionData['user_id'] = $matchedOrder->user_id;
                    $transactionData['status'] = 'Matched (Completed)';
                    $matchedOrders++;
                    $totalRevenue += $transactionAmount;

                    // Remove from giveaway orders so we can count unmatched orders
                    $giveawayOrders = $giveawayOrders->reject(function ($order) use ($matchedOrder) {
                        return $order->id === $matchedOrder->id;
                    });
                } else {
                    $transactionData['matched_order'] = false;
                    $transactionData['order_id'] = null;
                    $transactionData['user_id'] = null;
                    $transactionData['status'] = $this->getTransactionStatus($transactionData);
                }

                $transactions[] = $transactionData;
            }

            // Create list of unmatched orders (completed orders not matched to any transaction)
            $unmatchedOrders = [];
            foreach ($giveawayOrders as $order) {
                $unmatchedOrders[] = [
                    'user_id' => $order->user_id,
                    'order_id' => $order->id,
                    'user_email' => $order->user->email ?? 'N/A',
                    'order_total' => $order->total,
                    'credit_used' => $order->credit_used ?? 0,
                    'original_total' => $order->original_total ?? $order->total,
                    'ticket_numbers' => $order->giveaways->first()?->pivot?->numbers ?? [],
                    'ticket_amount' => $order->giveaways->first()?->pivot?->amount ?? 0,
                ];
            }

            $this->summary = [
                'total_transactions' => count($transactions),
                'matched_orders' => $matchedOrders,
                'unmatched_orders' => count($unmatchedOrders),
                'total_revenue' => $totalRevenue,
                'credit_usage' => $creditUsage,
                'processed_at' => now()->toISOString(),
                'status_breakdown' => $this->getStatusBreakdown($transactions),
            ];

            $this->details = [
                'transactions' => $transactions,
                'unmatched_orders' => $unmatchedOrders,
            ];

        } catch (\Exception $e) {
            Log::error("Error processing transaction sheet CSV: " . $e->getMessage());
            $this->summary = ['error' => $e->getMessage()];
        }
    }

    public function getTransactionStatus($transaction)
    {
        // Check if Merchant Transaction ID matches any order ID
        if (!empty($transaction['merchant_tx_id'])) {
            $order = Order::where('id', $transaction['merchant_tx_id'])->first();

            if ($order) {
                // Check if the order belongs to the same giveaway
                $belongsToGiveaway = $order->giveaways()
                    ->where('giveaways.id', $this->giveaway_id)
                    ->exists();

                if ($belongsToGiveaway) {
                    if ($order->status === 'completed') {
                        return 'Matched (Completed)';
                    } elseif ($order->status === 'failed') {
                        return 'Matched (Failed)';
                    } else {
                        return 'Matched (Pending)';
                    }
                } else {
                    return 'Order ID Mismatch';
                }
            } else {
                return 'Invalid Order ID';
            }
        }

        return 'Unmatched';
    }

    public function getStatusBreakdown($transactions)
    {
        $statusCounts = [
            'Matched (Completed)' => 0,
            'Matched (Failed)' => 0,
            'Matched (Pending)' => 0,
            'Order ID Mismatch' => 0,
            'Invalid Order ID' => 0,
            'Unmatched' => 0,
        ];

        foreach ($transactions as $transaction) {
            $status = $transaction['status'] ?? 'Unmatched';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            } else {
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }
        }

        return $statusCounts;
    }
}
