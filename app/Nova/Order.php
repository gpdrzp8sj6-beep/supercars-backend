<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\HasOne;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BooleanGroup;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class Order extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Order>
     */
    public static $model = \App\Models\Order::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'id';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
        'checkoutId',
        'user.email',
    ];

    /**
     * The relationships that should be eager loaded on index queries.
     *
     * @var array
     */
    public static $with = ['user', 'giveaways'];

    /**
     * Default ordering for index view.
     *
     * @var array
     */
    public static $orderBy = ['created_at' => 'desc'];

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('User', 'user', \App\Nova\User::class),
            Text::make('Bought Giveaways', function () {
                return $this->giveaways->map(function ($giveaway) {
                    $pivot = $giveaway->pivot;
                    $tickets = implode(',', json_decode($pivot->numbers ?? '[]'));
                    $giveawayUrl = "/admin/resources/giveaways/{$giveaway->id}";
                    return "<a href='{$giveawayUrl}' class='link-default' target='_blank'>Giveaway ID: {$giveaway->id}</a> - Tickets: {$tickets}";
                })->implode('<br>');
            })->asHtml()->onlyOnDetail(),


            Select::make('Status')
                ->options(fn () => [
                    'completed' => 'Completed',
                    'pending' => 'Pending',
                    'cancelled' => 'Cancelled',
                    'failed' => 'Failed',
                ]),
            Currency::make("Subtotal", 'original_total')->currency('GBP'),
            Currency::make("Amount Paid", 'total')->currency('GBP'),
            Currency::make("Credit Used", 'credit_used')->currency('GBP'),
            Text::make("Checkout ID", 'checkoutId')->hideFromIndex(),
            Badge::make('Payment Method', function () {
                if ($this->credit_used > 0) {
                    return $this->checkoutId ? 'Mixed (Credit + Payment)' : 'Credit Only';
                } elseif ($this->checkoutId) {
                    return 'Payment Gateway';
                }
                return 'Unknown';
            })->map([
                'Credit Only' => 'success',
                'Payment Gateway' => 'info',
                'Mixed (Credit + Payment)' => 'warning',
                'Unknown' => 'danger',
            ]),

            Badge::make('Merchant TX Status', function () {
                // Check if this order ID appears as merchant transaction ID in any transaction sheet
                // AND verify customer email matches
                $transactionSheets = \App\Models\TransactionSheet::whereNotNull('details')->get();

                $bestMatch = null;
                $matchType = 'Unmatched';
                $mismatchedOrder = null;

                foreach ($transactionSheets as $sheet) {
                    $transactions = $sheet->details['transactions'] ?? [];
                    foreach ($transactions as $transaction) {
                        $merchantTxId = $transaction['merchant_tx_id'] ?? null;
                        $customerEmail = $transaction['customer_email'] ?? null;

                        // Check for exact merchant TX ID match with this order
                        if ($merchantTxId == $this->id &&
                            $customerEmail &&
                            $this->user &&
                            strcasecmp($customerEmail, $this->user->email) === 0) {

                            // Found exact match for this order
                            $matchType = 'Matched';
                            if ($this->status === 'completed') {
                                return 'Matched (Completed)';
                            } elseif ($this->status === 'failed') {
                                return 'Matched (Failed)';
                            } else {
                                return 'Matched (Pending)';
                            }
                        }

                        // Check if merchant TX ID exists for a different order
                        if ($merchantTxId && $merchantTxId != $this->id) {
                            $otherOrder = \App\Models\Order::find($merchantTxId);
                            if ($otherOrder) {
                                $mismatchedOrder = $otherOrder;
                                $matchType = 'ID Mismatch';
                            }
                        }

                        // Check for email match (for informational purposes)
                        if ($customerEmail &&
                            $this->user &&
                            strcasecmp($customerEmail, $this->user->email) === 0 &&
                            !$bestMatch) {
                            $bestMatch = [
                                'merchant_tx_id' => $merchantTxId,
                                'transaction' => $transaction,
                                'sheet' => $sheet
                            ];
                        }
                    }
                }

                // Handle different match scenarios
                if ($matchType === 'ID Mismatch' && $mismatchedOrder) {
                    return 'ID Mismatch';
                }

                if ($matchType === 'Unmatched' && $bestMatch) {
                    $foundId = $bestMatch['merchant_tx_id'];
                    if ($foundId) {
                        return 'Email Match';
                    } else {
                        return 'Email Match (No TX ID)';
                    }
                }
                return 'Unmatched';
            })->map([
                'Matched (Completed)' => 'success',
                'Matched (Failed)' => 'danger',
                'Matched (Pending)' => 'info',
                'ID Mismatch' => 'danger',
                'Email Match' => 'warning',
                'Email Match (No TX ID)' => 'warning',
                'Unmatched' => 'warning',
            ])->sortable(),

            Text::make('Payment Links', function () {
                $links = [];

                // Only show links for this specific order's mismatches
                $transactionSheets = \App\Models\TransactionSheet::whereNotNull('details')->get();
                $hasMismatch = false;
                $hasEmailMatch = false;

                foreach ($transactionSheets as $sheet) {
                    $transactions = $sheet->details['transactions'] ?? [];
                    foreach ($transactions as $transaction) {
                        $merchantTxId = $transaction['merchant_tx_id'] ?? null;
                        $customerEmail = $transaction['customer_email'] ?? null;

                        // Check if this order's email matches a transaction with different TX ID
                        if ($customerEmail &&
                            $this->user &&
                            strcasecmp($customerEmail, $this->user->email) === 0 &&
                            $merchantTxId &&
                            $merchantTxId != $this->id &&
                            !$hasEmailMatch) {

                            $otherOrder = \App\Models\Order::find($merchantTxId);
                            if ($otherOrder) {
                                $otherOrderStatus = ucfirst($otherOrder->status);
                                $links[] = "<a href='/admin/resources/orders/{$merchantTxId}' class='link-default' target='_blank'>Order {$merchantTxId} ({$otherOrderStatus})</a> - Email Match";
                                $hasEmailMatch = true;
                            }
                        }

                        // Check if merchant TX ID exists for a different order (ID mismatch for this order)
                        if ($merchantTxId && $merchantTxId != $this->id && !$hasMismatch) {
                            $otherOrder = \App\Models\Order::find($merchantTxId);
                            if ($otherOrder) {
                                // Verify this transaction also matches by email to avoid false positives
                                if ($customerEmail &&
                                    $this->user &&
                                    strcasecmp($customerEmail, $this->user->email) === 0) {
                                    $otherOrderStatus = ucfirst($otherOrder->status);
                                    $links[] = "<a href='/admin/resources/orders/{$merchantTxId}' class='link-default' target='_blank'>Order {$merchantTxId} ({$otherOrderStatus})</a> - ID Mismatch";
                                    $hasMismatch = true;
                                }
                            }
                        }
                    }
                }

                return $links ? implode(' | ', $links) : 'No navigation links';
            })->asHtml()->onlyOnDetail(),

            Text::make('Transaction Sheets', function () {
                $matchingSheets = $this->getMatchingTransactionSheets();
                if ($matchingSheets->isEmpty()) {
                    return 'No transaction sheets found containing this payment record.';
                }

                $sheetInfo = [];
                foreach ($matchingSheets as $sheet) {
                    $giveawayTitle = $sheet->giveaway ? $sheet->giveaway->title : 'Unknown Giveaway';
                    $sheetInfo[] = "Sheet #{$sheet->id} ({$sheet->filename}) - Giveaway: {$giveawayTitle}";
                }

                return implode("\n", $sheetInfo);
            })->onlyOnDetail()->asHtml(),

            // Comprehensive Payment Matching Information
            Text::make('Payment Matching Summary', function () {
                return $this->getMatchingSummary();
            })->onlyOnDetail()->asHtml(),

            Text::make('Detailed Payment Matching', function () {
                $info = $this->getDetailedMatchingInfo();

                $html = "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                $html .= "<h4 style='margin-top: 0; color: #495057;'>Payment Reconciliation Details</h4>";

                // Overall Status
                $statusColor = match($info['overall_match_status']) {
                    'matched' => '#28a745',
                    'no_matches' => '#dc3545',
                    'no_transaction_sheets' => '#ffc107',
                    default => '#6c757d'
                };

                $statusExplanation = match($info['overall_match_status']) {
                    'matched' => 'Found transaction(s) matching this order by email, amount, or other criteria',
                    'no_matches' => 'No transactions found that match this order',
                    'no_transaction_sheets' => 'No transaction sheets uploaded for associated giveaways',
                    default => 'Unknown matching status'
                };

                $html .= "<p><strong>Overall Status:</strong> <span style='color: {$statusColor};'>{$info['overall_match_status']}</span></p>";
                $html .= "<p style='font-size: 0.9em; color: #6c757d; margin-top: 5px;'><em>{$statusExplanation}</em></p>";

                // Order Information in table format
                $html .= "<div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #007bff;'>";
                $html .= "<h4 style='margin-top: 0; color: #007bff;'>📊 Matching Logic Summary</h4>";
                $html .= "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px;'>";

                $html .= "<div>";
                $html .= "<strong style='color: #28a745;'>✅ Exact Match (Best)</strong><br>";
                $html .= "<small>• Merchant TX ID = Order ID<br>• Customer Email matches<br>• Amount matches</small>";
                $html .= "</div>";

                $html .= "<div>";
                $html .= "<strong style='color: #ffc107;'>⚠️ Email Match Only</strong><br>";
                $html .= "<small>• Email matches<br>• Amount may match<br>• Merchant TX ID differs</small>";
                $html .= "</div>";

                $html .= "<div>";
                $html .= "<strong style='color: #dc3545;'>❌ No Match</strong><br>";
                $html .= "<small>• No matching transaction<br>• Check email spelling<br>• Verify amounts</small>";
                $html .= "</div>";

                $html .= "<div>";
                $html .= "<strong style='color: #6c757d;'>ℹ️ Status Meanings</strong><br>";
                $html .= "<small>• <strong>Merchant TX Status:</strong> Exact ID match<br>• <strong>Overall Status:</strong> Any match found</small>";
                $html .= "</div>";

                $html .= "</div>";
                $html .= "</div>";
                $html .= "<table style='width: 100%; border-collapse: collapse; margin-top: 5px;'>";
                $html .= "<thead><tr>";
                $html .= "<th style='padding: 8px; border: 1px solid #dee2e6; background: #f8f9fa; text-align: left;'>Order ID</th>";
                $html .= "<th style='padding: 8px; border: 1px solid #dee2e6; background: #f8f9fa; text-align: left;'>User ID</th>";
                $html .= "<th style='padding: 8px; border: 1px solid #dee2e6; background: #f8f9fa; text-align: left;'>User Email</th>";
                $html .= "<th style='padding: 8px; border: 1px solid #dee2e6; background: #f8f9fa; text-align: left;'>Order Total</th>";
                $html .= "<th style='padding: 8px; border: 1px solid #dee2e6; background: #f8f9fa; text-align: left;'>Status</th>";
                $html .= "<th style='padding: 8px; border: 1px solid #dee2e6; background: #f8f9fa; text-align: left;'>Giveaway IDs</th>";
                $html .= "</tr></thead>";
                $html .= "<tbody><tr>";
                $html .= "<td style='padding: 8px; border: 1px solid #dee2e6;'>{$info['order_id']}</td>";
                $html .= "<td style='padding: 8px; border: 1px solid #dee2e6;'>{$info['user_id']}</td>";
                $html .= "<td style='padding: 8px; border: 1px solid #dee2e6;'>" . ($info['user_email'] ?? 'N/A') . "</td>";
                $html .= "<td style='padding: 8px; border: 1px solid #dee2e6;'>£" . number_format($info['order_total'], 2) . "</td>";
                $html .= "<td style='padding: 8px; border: 1px solid #dee2e6;'>{$info['status']}</td>";
                $html .= "<td style='padding: 8px; border: 1px solid #dee2e6;'>" . implode(', ', $info['giveaway_ids']) . "</td>";
                $html .= "</tr></tbody>";
                $html .= "</table>";
                $html .= "</div>";

                // Best Match - Show email match first, then best score match
                if (isset($info['best_match'])) {
                    // Look for email-matched transaction first
                    $emailMatch = null;
                    $userEmail = $info['user_email'];

                    if ($userEmail && isset($info['all_matches'])) {
                        foreach ($info['all_matches'] as $match) {
                            $transaction = $match['transaction'];
                            if ((isset($transaction['email']) && strcasecmp($transaction['email'], $userEmail) === 0) ||
                                (isset($transaction['customer_email']) && strcasecmp($transaction['customer_email'], $userEmail) === 0)) {
                                $emailMatch = $match;
                                break;
                            }
                        }
                    }

                    // Only show if we found an email match
                    if ($emailMatch) {
                        $displayMatch = $emailMatch;

                        // Check if this is an exact merchant TX ID match or just email match
                        $merchantTxId = $displayMatch['transaction']['merchant_tx_id'] ?? null;
                        $isExactMatch = ($merchantTxId == $this->id);

                        $matchType = $isExactMatch ? '🎯 Exact Match' : '📧 Email Match Only';
                        $matchColor = $isExactMatch ? '#0c5460' : '#856404';
                        $bgColor = $isExactMatch ? '#d1ecf1' : '#fff3cd';
                        $borderColor = $isExactMatch ? '#bee5eb' : '#ffeaa7';

                        $html .= "<div style='background: {$bgColor}; border: 1px solid {$borderColor}; padding: 10px; border-radius: 3px; margin: 10px 0;'>";
                        $html .= "<strong style='color: {$matchColor};'>{$matchType}:</strong><br>";
                        $html .= "Sheet #{$displayMatch['sheet_id']} ({$displayMatch['sheet_filename']})<br>";
                        $html .= "Giveaway: {$displayMatch['giveaway_title']}<br>";
                        $html .= "Match Score: {$displayMatch['match_score']}/8<br>";
                        $html .= "Matched Fields: " . implode(', ', $displayMatch['matched_fields']) . "<br>";

                        if (!$isExactMatch) {
                            $html .= "<br><strong style='color: #856404;'>⚠️ Merchant TX ID Mismatch:</strong><br>";
                            $html .= "Expected Order ID: <strong>{$this->id}</strong><br>";
                            $html .= "Found Merchant TX ID: <strong>{$merchantTxId}</strong><br>";
                            $html .= "<em>This transaction matches by email/amount but has a different Order ID</em>";
                        }

                        $html .= "<br><strong>Complete Transaction Data:</strong><br>";
                        $html .= "<table style='width: 100%; border-collapse: collapse; margin-top: 5px; border: 1px solid #bee5eb;'>";

                        if (isset($displayMatch['transaction']) && is_array($displayMatch['transaction'])) {
                            // Define the columns to display
                            $columnsToShow = [
                                'transaction_id' => 'Transaction ID',
                                'merchant_tx_id' => 'Merchant Transaction ID',
                                'auth_code' => 'Auth Code',
                                'amount' => 'Amount',
                                'last_four_digits' => 'Last Four Digits',
                                'name_surname' => 'Name/Surname',
                                'customer_email' => 'Customer Email',
                                'short_id' => 'Short ID'
                            ];

                            // Header row
                            $html .= "<thead><tr>";
                            foreach ($columnsToShow as $key => $displayName) {
                                $html .= "<th style='padding: 8px; border: 1px solid #dee2e6; background: #f8f9fa; text-align: left;'>{$displayName}</th>";
                            }
                            $html .= "</tr></thead>";

                            // Data row
                            $html .= "<tbody><tr>";
                            foreach ($columnsToShow as $key => $displayName) {
                                $value = $displayMatch['transaction'][$key] ?? '';
                                $displayValue = $value;

                                // Format specific fields
                                if ($key === 'amount' && is_numeric($value)) {
                                    $displayValue = '£' . number_format((float)$value, 2);
                                } elseif (is_bool($value)) {
                                    $displayValue = $value ? 'Yes' : 'No';
                                } elseif ($value === null || $value === '') {
                                    $displayValue = '<em>(empty)</em>';
                                }

                                $html .= "<td style='padding: 8px; border: 1px solid #dee2e6;'>{$displayValue}</td>";
                            }
                            $html .= "</tr></tbody>";
                        } else {
                            $html .= "<tr><td colspan='8' style='padding: 8px; text-align: center; font-style: italic;'>No transaction data available</td></tr>";
                        }

                        $html .= "</table>";
                        $html .= "</div>";
                    } else {
                        // No email match found
                        $html .= "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 3px; margin: 10px 0;'>";
                        $html .= "<strong style='color: #721c24;'>❌ No Email Match Found:</strong><br>";
                        $html .= "No transaction found with customer email: <strong>" . ($userEmail ?? 'N/A') . "</strong><br>";
                        $html .= "Available matches are based on amount only, which may not be accurate.<br>";
                        $html .= "<em>Check if the email address in the transaction sheet matches exactly.</em>";
                        $html .= "</div>";
                    }
                }



                // Mismatches - Keep concise
                if (!empty($info['mismatches'])) {
                    $html .= "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 3px; margin: 10px 0;'>";
                    $html .= "<strong style='color: #721c24;'>❌ Issues Found:</strong><br>";
                    $uniqueMessages = [];
                    foreach ($info['mismatches'] as $mismatch) {
                        $message = $mismatch['message'] ?? $mismatch['reason'] ?? 'Unknown issue';
                        $uniqueMessages[$message] = ($uniqueMessages[$message] ?? 0) + 1;
                    }
                    foreach ($uniqueMessages as $message => $count) {
                        $countText = $count > 1 ? " ({$count} instances)" : "";
                        $html .= "• {$message}{$countText}<br>";
                    }
                    $html .= "</div>";
                }



                $html .= "</div>";
                return $html;
            })->onlyOnDetail()->asHtml(),
            Text::make("Forenames")->hideFromIndex(),
            Text::make("Surname")->hideFromIndex(),
            Text::make("Phone"),
            Text::make("Address Line 1", 'address_line_1')->hideFromIndex(),
            Text::make("Address Line 2", 'address_line_2')->hideFromIndex(),
            Text::make("City")->hideFromIndex(),
            Text::make("Postal Code", 'post_code')->hideFromIndex(),
            Text::make("Country")->hideFromIndex(),
            DateTime::make("Created At", 'created_at'),
            DateTime::make("Last Updated At", 'updated_at')->hideFromIndex(),
        ];
    }

    /**
     * Get the cards available for the resource.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [
            new \App\Nova\Filters\OrderStatusFilter,
        ];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, \Laravel\Nova\Lenses\Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [
            new \App\Nova\Lenses\FailedOrders,
            new \App\Nova\Lenses\UnpaidTickets,
            new \App\Nova\Lenses\CreditPaidOrders,
        ];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array<int, \Laravel\Nova\Actions\Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            new \App\Nova\Actions\CompleteOrder,
            new \App\Nova\Actions\FailOrder,
            new \App\Nova\Actions\ReassignTicketNumbers,
            new \App\Nova\Actions\RemoveTickets,
        ];
    }
}
