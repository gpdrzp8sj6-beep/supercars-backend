<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\OrderCompleted;
use App\Mail\OrderReceived;
use App\Models\CreditTransaction;

class Order extends Model
{
      protected $fillable = [
          'user_id',
          'status',
          'total',
          'forenames',
          'surname',
          'phone',
          'address_line_1',
          'address_line_2',
          'city',
          'post_code',
          'country',
          'checkoutId',
          'cart',
          'credit_used',
      ];

    protected function casts() {
        return [
                'total' => 'float',
                'status' => 'string',
                'cart' => 'array',
                'credit_used' => 'decimal:2',
            ];
    }

    protected $appends = ['original_total'];

    /**
     * Get the original order total before credit deduction.
     */
    public function getOriginalTotalAttribute(): float
    {
        return (float) $this->total + (float) $this->credit_used;
    }

    protected static function booted(): void
    {
        // Log when checkoutId is set
        static::updated(function (Order $order) {
            if ($order->wasChanged('checkoutId')) {
                Log::info('Order checkoutId changed', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'old_checkout_id' => $order->getOriginal('checkoutId'),
                    'new_checkout_id' => $order->checkoutId,
                    'status' => $order->status,
                    'timestamp' => now()->toISOString()
                ]);
            }
        });

        // On update: when status transitions to completed or failed, send payment confirmation email
        static::updated(function (Order $order) {
            if ($order->wasChanged('status')) {
                $original = $order->getOriginal('status');
                $newStatus = $order->status;
                
                Log::info('Order status changed', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'from_status' => $original,
                    'to_status' => $newStatus,
                    'checkout_id' => $order->checkoutId,
                    'has_tickets_assigned' => $order->giveaways()->count() > 0,
                    'timestamp' => now()->toISOString()
                ]);
                
                // Refund credits if order fails and credits were used
                if ($newStatus === 'failed' && $order->credit_used > 0) {
                    $order->user->credit += $order->credit_used;
                    $order->user->save();
                    
                    CreditTransaction::create([
                        'user_id' => $order->user_id,
                        'amount' => $order->credit_used,
                        'type' => 'add',
                        'description' => 'Refund for failed order ' . $order->id,
                    ]);
                    
                    Log::info('Credits refunded for failed order', [
                        'order_id' => $order->id,
                        'amount' => $order->credit_used,
                        'user_id' => $order->user_id
                    ]);
                }

                // Revoke tickets if order fails and tickets were assigned
                if ($newStatus === 'failed' && in_array($original, ['pending', 'completed'])) {
                    $order->giveaways()->detach();
                    Log::info('Tickets revoked for failed order', [
                        'order_id' => $order->id,
                        'previous_status' => $original
                    ]);
                }
                
                // Send order received email when status changes to pending
                if ($newStatus === 'pending' && $original === 'created') {
                    try {
                        $email = $order->user?->email;
                        if ($email) {
                            Mail::to($email)->send(new OrderReceived($order));
                            Log::info('Order received email sent for pending status.', ['order_id' => $order->id]);
                        } else {
                            Log::warning('Order status changed to pending but user email missing.', ['order_id' => $order->id]);
                        }
                    } catch (\Throwable $ex) {
                        Log::error('Failed to send order received email for pending status: ' . $ex->getMessage(), [
                            'order_id' => $order->id,
                            'exception' => $ex,
                        ]);
                    }
                }
                
                // Send payment confirmation email when payment is resolved (completed or failed)
                if ($original !== $newStatus && in_array($newStatus, ['completed', 'failed']) && !app()->bound('webhook_processing')) {
                    try {
                        $email = $order->user?->email;
                        if ($email) {
                            // Ensure giveaways relationship is fresh loaded with pivot data for email
                            $order->load(['giveaways' => function($query) {
                                $query->withPivot(['numbers', 'amount']);
                            }]);
                            
                            // Log ticket information before sending email
                            $ticketInfo = [];
                            foreach ($order->giveaways as $giveaway) {
                                $numbers = json_decode($giveaway->pivot->numbers ?? '[]', true);
                                $ticketInfo[] = "Giveaway {$giveaway->id}: " . implode(', ', $numbers);
                            }
                            Log::info('Sending payment confirmation email from model', [
                                'order_id' => $order->id,
                                'user_email' => $email,
                                'payment_status' => $newStatus,
                                'giveaways_count' => $order->giveaways->count(),
                                'ticket_numbers' => $ticketInfo,
                                'has_pivot_numbers' => $order->giveaways->first()?->pivot?->numbers ? 'yes' : 'no'
                            ]);
                            
                            Mail::to($email)->send(new OrderCompleted($order));
                            Log::info('Payment confirmation email sent successfully from model.', [
                                'order_id' => $order->id,
                                'status' => $newStatus
                            ]);
                        } else {
                            Log::warning('Payment status updated but user email missing.', [
                                'order_id' => $order->id,
                                'status' => $newStatus
                            ]);
                        }
                    } catch (\Throwable $ex) {
                        Log::error('Failed to send payment confirmation email from model: ' . $ex->getMessage(), [
                            'order_id' => $order->id,
                            'status' => $newStatus,
                            'exception' => $ex,
                        ]);
                    }
                }
            }
        });
    }

    public function giveaways(): BelongsToMany
    {
        return $this->belongsToMany(Giveaway::class)
                    ->withPivot('numbers', 'amount')
                    ->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get transaction sheets that contain this order's payment record
     */
    public function getMatchingTransactionSheets()
    {
        return \App\Models\TransactionSheet::where('giveaway_id', function ($query) {
            $query->select('giveaway_id')
                  ->from('giveaway_order')
                  ->where('order_id', $this->id)
                  ->limit(1);
        })
        ->where(function ($query) {
            $query->whereJsonContains('details->transactions', ['user_id' => $this->user_id, 'order_id' => $this->id])
                  ->orWhere(function ($subQuery) {
                      $subQuery->whereJsonContains('details->transactions', ['merchant_tx_id' => (string)$this->id])
                               ->whereJsonContains('details->transactions', ['order_id' => $this->id]);
                  });
        })
        ->with('giveaway')
        ->get();
    }

    /**
     * Check if this order has a payment record in any transaction sheet
     */
    public function hasPaymentRecord()
    {
        return $this->getMatchingTransactionSheets()->isNotEmpty();
    }

    /**
     * Get detailed matching information for this order
     */
    public function getDetailedMatchingInfo()
    {
        $giveawayIds = $this->giveaways->pluck('id')->toArray();
        $userEmail = $this->user->email ?? null;
        $orderTotal = $this->total;

        $matchingInfo = [
            'order_id' => $this->id,
            'user_id' => $this->user_id,
            'user_email' => $userEmail,
            'order_total' => $orderTotal,
            'giveaway_ids' => $giveawayIds,
            'status' => $this->status,
            'matches' => [],
            'mismatches' => [],
            'overall_match_status' => 'no_transaction_sheets',
        ];

        // Get all transaction sheets for the giveaways this order is associated with
        $transactionSheets = \App\Models\TransactionSheet::whereIn('giveaway_id', $giveawayIds)
            ->with('giveaway')
            ->get();

        if ($transactionSheets->isEmpty()) {
            $matchingInfo['mismatches'][] = [
                'type' => 'no_transaction_sheets',
                'message' => 'No transaction sheets found for associated giveaways',
                'giveaway_ids' => $giveawayIds
            ];
            return $matchingInfo;
        }

        $hasAnyMatch = false;
        $bestMatch = null;
        $bestMatchScore = 0;
        $allMatches = [];
        $exactMatches = []; // Store exact matches (user_id + amount + email)

        foreach ($transactionSheets as $sheet) {
            $sheetMatches = [];
            $sheetMismatches = [];

            // Look for transactions in this sheet
            $transactions = $sheet->details['transactions'] ?? [];

            foreach ($transactions as $transaction) {
                $matchScore = 0;
                $matchDetails = [];

                // Check user ID match
                $userIdMatch = false;
                if (isset($transaction['user_id']) && $transaction['user_id'] == $this->user_id) {
                    $userIdMatch = true;
                    $matchScore += 3;
                    $matchDetails[] = 'user_id';
                } elseif (isset($transaction['merchant_tx_id']) && $transaction['merchant_tx_id'] == $this->id) {
                    $userIdMatch = true;
                    $matchScore += 3;
                    $matchDetails[] = 'merchant_tx_id';
                }

                // Check email match (if available in transaction data)
                $emailMatch = false;
                if ((isset($transaction['email']) && $userEmail && strcasecmp($transaction['email'], $userEmail) === 0) ||
                    (isset($transaction['customer_email']) && $userEmail && strcasecmp($transaction['customer_email'], $userEmail) === 0)) {
                    $emailMatch = true;
                    $matchScore += 2;
                    $matchDetails[] = 'email';
                }

                // Check amount match
                $amountMatch = false;
                $transactionAmount = isset($transaction['amount']) ? (float) $transaction['amount'] : 0;
                if (abs($transactionAmount - $orderTotal) < 0.01) { // Allow for small floating point differences
                    $amountMatch = true;
                    $matchScore += 2;
                    $matchDetails[] = 'amount';
                }

                // Check order ID match
                $orderIdMatch = false;
                if (isset($transaction['order_id']) && $transaction['order_id'] == $this->id) {
                    $orderIdMatch = true;
                    $matchScore += 1;
                    $matchDetails[] = 'order_id';
                }

                if ($userIdMatch || $emailMatch || $amountMatch) {
                    $hasAnyMatch = true;

                    $matchData = [
                        'sheet_id' => $sheet->id,
                        'sheet_filename' => $sheet->filename,
                        'giveaway_id' => $sheet->giveaway_id,
                        'giveaway_title' => $sheet->giveaway->title ?? 'Unknown',
                        'transaction' => $transaction,
                        'match_score' => $matchScore,
                        'matched_fields' => $matchDetails,
                    ];

                    $allMatches[] = $matchData;

                    // Track exact matches (high confidence)
                    if ($matchScore >= 5) { // user_id + email or user_id + amount, etc.
                        $exactMatches[] = $matchData;
                    }

                    if ($matchScore > $bestMatchScore) {
                        $bestMatchScore = $matchScore;
                        $bestMatch = $matchData;
                    }

                    $sheetMatches[] = [
                        'transaction' => $transaction,
                        'matched_fields' => $matchDetails,
                        'match_score' => $matchScore,
                        'user_id_match' => $userIdMatch,
                        'email_match' => $emailMatch,
                        'amount_match' => $amountMatch,
                        'order_id_match' => $orderIdMatch,
                    ];
                }
            }

            if (!empty($sheetMatches)) {
                // Group matches by type for this sheet
                $groupedMatches = [];
                foreach ($sheetMatches as $match) {
                    $key = implode(',', $match['matched_fields']) . '_score_' . $match['match_score'];
                    if (!isset($groupedMatches[$key])) {
                        $groupedMatches[$key] = [
                            'fields' => $match['matched_fields'],
                            'score' => $match['match_score'],
                            'transactions' => []
                        ];
                    }
                    $groupedMatches[$key]['transactions'][] = $match;
                }

                $matchingInfo['matches'][] = [
                    'sheet_id' => $sheet->id,
                    'sheet_filename' => $sheet->filename,
                    'giveaway_id' => $sheet->giveaway_id,
                    'giveaway_title' => $sheet->giveaway->title ?? 'Unknown',
                    'transaction_matches' => $sheetMatches,
                    'grouped_matches' => $groupedMatches,
                    'total_matches' => count($sheetMatches),
                ];
            } else {
                $sheetMismatches[] = [
                    'sheet_id' => $sheet->id,
                    'sheet_filename' => $sheet->filename,
                    'reason' => 'no_matching_transactions',
                    'message' => 'No transactions in this sheet match the order criteria'
                ];
            }

            if (!empty($sheetMismatches)) {
                $matchingInfo['mismatches'] = array_merge($matchingInfo['mismatches'], $sheetMismatches);
            }
        }

        // Determine overall match status
        if ($hasAnyMatch) {
            $matchingInfo['overall_match_status'] = 'matched';
            $matchingInfo['best_match'] = $bestMatch;
            $matchingInfo['all_matches'] = $allMatches;
            $matchingInfo['total_match_count'] = count($allMatches);
            $matchingInfo['exact_matches'] = $exactMatches;
            $matchingInfo['exact_match_count'] = count($exactMatches);
        } else {
            $matchingInfo['overall_match_status'] = 'no_matches';
            $matchingInfo['mismatches'][] = [
                'type' => 'no_matches_found',
                'message' => 'No matching transactions found in any transaction sheet for this order'
            ];
        }

        return $matchingInfo;
    }

    /**
     * Get a human-readable summary of matching status
     */
    public function getMatchingSummary()
    {
        $info = $this->getDetailedMatchingInfo();

        switch ($info['overall_match_status']) {
            case 'matched':
                $bestMatch = $info['best_match'];
                $matchedFields = implode(', ', $bestMatch['matched_fields']);
                return "✅ Payment record found in sheet #{$bestMatch['sheet_id']} ({$bestMatch['sheet_filename']}) - Matched: {$matchedFields}";

            case 'no_transaction_sheets':
                return "⚠️ No transaction sheets available for associated giveaways";

            case 'no_matches':
                return "❌ No matching payment records found in transaction sheets";

            default:
                return "❓ Unable to determine matching status";
        }
    }

    /**
     * Get detailed mismatch information for logging/debugging
     */
    public function getMismatchDetails()
    {
        $info = $this->getDetailedMatchingInfo();
        return $info['mismatches'];
    }

    /**
     * Log comprehensive matching information for this order
     */
    public function logMatchingInfo($context = 'manual_check')
    {
        $info = $this->getDetailedMatchingInfo();

        Log::info('Order Payment Matching Analysis', [
            'context' => $context,
            'order_id' => $info['order_id'],
            'user_id' => $info['user_id'],
            'user_email' => $info['user_email'],
            'order_total' => $info['order_total'],
            'order_status' => $info['status'],
            'giveaway_ids' => $info['giveaway_ids'],
            'overall_match_status' => $info['overall_match_status'],
            'matches_count' => count($info['matches']),
            'mismatches_count' => count($info['mismatches']),
            'best_match_score' => $info['best_match']['match_score'] ?? 0,
            'best_match_sheet_id' => $info['best_match']['sheet_id'] ?? null,
            'matched_fields' => $info['best_match']['matched_fields'] ?? [],
            'mismatch_details' => $info['mismatches'],
            'timestamp' => now()->toISOString(),
        ]);

        return $info;
    }

    /**
     * Get a summary of what fields matched and didn't match
     */
    public function getFieldMatchingAnalysis()
    {
        $info = $this->getDetailedMatchingInfo();
        $analysis = [
            'order_id' => $this->id,
            'user_email' => $this->user->email ?? null,
            'order_amount' => $this->total,
            'matched_fields' => [],
            'unmatched_fields' => [],
            'field_match_details' => []
        ];

        if ($info['overall_match_status'] === 'matched' && isset($info['best_match'])) {
            $bestMatch = $info['best_match'];
            $transaction = $bestMatch['transaction'];

            // Check each field
            $fieldChecks = [
                'user_id' => [
                    'order_value' => $this->user_id,
                    'transaction_value' => $transaction['user_id'] ?? $transaction['merchant_tx_id'] ?? null,
                    'matched' => in_array('user_id', $bestMatch['matched_fields']) || in_array('merchant_tx_id', $bestMatch['matched_fields'])
                ],
                'email' => [
                    'order_value' => $this->user->email ?? null,
                    'transaction_value' => $transaction['email'] ?? null,
                    'matched' => in_array('email', $bestMatch['matched_fields'])
                ],
                'amount' => [
                    'order_value' => $this->total,
                    'transaction_value' => $transaction['amount'] ?? null,
                    'matched' => in_array('amount', $bestMatch['matched_fields'])
                ],
                'order_id' => [
                    'order_value' => $this->id,
                    'transaction_value' => $transaction['order_id'] ?? null,
                    'matched' => in_array('order_id', $bestMatch['matched_fields'])
                ]
            ];

            foreach ($fieldChecks as $field => $check) {
                if ($check['matched']) {
                    $analysis['matched_fields'][] = $field;
                } else {
                    $analysis['unmatched_fields'][] = $field;
                }

                $analysis['field_match_details'][$field] = [
                    'matched' => $check['matched'],
                    'order_value' => $check['order_value'],
                    'transaction_value' => $check['transaction_value'],
                    'values_match' => $check['order_value'] == $check['transaction_value']
                ];
            }
        }

        return $analysis;
    }
}
