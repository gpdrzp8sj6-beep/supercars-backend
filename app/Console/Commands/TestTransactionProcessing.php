<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestTransactionProcessing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:transaction-processing';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the transaction processing logic with the uploaded CSV';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $csvFilePath = storage_path('app/public/uploads/TP Transactions 2025-10-01,2025-10-29.csv');

        if (!file_exists($csvFilePath)) {
            $this->error("CSV file not found at $csvFilePath");
            return 1;
        }

        $data = [];
        if (($handle = fopen($csvFilePath, "r")) !== FALSE) {
            $header = fgetcsv($handle, 1000, ",");
            while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $data[] = array_combine($header, $row);
            }
            fclose($handle);
        }

        $this->info("Parsed " . count($data) . " transactions from CSV.");

        $report = [
            'total_transactions' => count($data),
            'matched_orders' => 0,
            'unmatched_transactions' => 0,
            'total_revenue' => 0,
            'credit_used_total' => 0,
            'paid_with_credit' => 0,
            'paid_with_gateway' => 0,
            'mixed_payments' => 0,
        ];

        $details = [];

        foreach ($data as $transaction) {
            $checkoutId = $transaction['Merchant Transaction ID'] ?? '';
            $customerEmail = $transaction['Customer Email'] ?? '';
            $amount = (float) str_replace(',', '', $transaction['Amount'] ?? 0);
            $status = $transaction['Status'] ?? '';

            if ($status !== 'Success') {
                continue;
            }

            $report['total_revenue'] += $amount;

            // Enhanced matching: Use both Merchant Transaction ID (order ID) AND Customer Email
            $order = null;
            
            if (!empty($checkoutId) && !empty($customerEmail)) {
                // Primary match: Order ID + User Email
                $order = \App\Models\Order::where('id', $checkoutId)
                    ->whereHas('user', function($query) use ($customerEmail) {
                        $query->where('email', $customerEmail);
                    })
                    ->first();
                    
                if (!$order) {
                    // Fallback: Try matching by checkoutId for the same user
                    $order = \App\Models\Order::where('checkoutId', 'like', '%' . $checkoutId . '%')
                        ->whereHas('user', function($query) use ($customerEmail) {
                            $query->where('email', $customerEmail);
                        })
                        ->first();
                }
            }
            
            // If still no match, try legacy matching methods
            if (!$order && !empty($checkoutId)) {
                $order = \App\Models\Order::find($checkoutId);
                if (!$order) {
                    $order = \App\Models\Order::where('checkoutId', 'like', '%' . $checkoutId . '%')->first();
                }
            }

            if ($order) {
                $report['matched_orders']++;
                $paymentMethod = 'Unknown';

                if ($order->credit_used > 0) {
                    if ($order->total > 0) {
                        $paymentMethod = 'Mixed (Credit + Payment)';
                        $report['mixed_payments']++;
                    } else {
                        $paymentMethod = 'Credit Only';
                        $report['paid_with_credit']++;
                    }
                } elseif ($order->total > 0) {
                    $paymentMethod = 'Payment Gateway';
                    $report['paid_with_gateway']++;
                }

                $report['credit_used_total'] += $order->credit_used;

                $details[] = [
                    'Transaction ID' => $transaction['Transaction ID'] ?? '',
                    'Merchant Transaction ID' => $checkoutId,
                    'Customer Email' => $customerEmail,
                    'Amount' => $amount,
                    'Order ID' => $order->id,
                    'Order Status' => $order->status,
                    'User Email' => $order->user->email ?? '',
                    'Email Match' => ($customerEmail === $order->user->email) ? 'YES' : 'NO',
                    'Payment Method' => $paymentMethod,
                    'Credit Used' => $order->credit_used,
                    'Amount Paid' => $order->total,
                    'Original Total' => $order->original_total,
                    'Transaction Date' => $transaction['Date'] ?? '',
                ];
            } else {
                $report['unmatched_transactions']++;
                $details[] = [
                    'Transaction ID' => $transaction['Transaction ID'] ?? '',
                    'Merchant Transaction ID' => $checkoutId,
                    'Customer Email' => $customerEmail,
                    'Amount' => $amount,
                    'Order ID' => 'Not Found',
                    'Order Status' => 'N/A',
                    'User Email' => 'N/A',
                    'Email Match' => 'N/A',
                    'Payment Method' => 'N/A',
                    'Credit Used' => 0,
                    'Amount Paid' => 0,
                    'Original Total' => 0,
                    'Transaction Date' => $transaction['Date'] ?? '',
                ];
            }
        }

        $report['details'] = $details;

        $this->info("Processing complete:");
        $this->line("Total Transactions: " . $report['total_transactions']);
        $this->line("Matched Orders: " . $report['matched_orders']);
        $this->line("Unmatched Transactions: " . $report['unmatched_transactions']);
        $this->line("Total Revenue: £" . number_format($report['total_revenue'], 2));
        $this->line("Total Credit Used: £" . number_format($report['credit_used_total'], 2));
        $this->line("Paid with Credit Only: " . $report['paid_with_credit']);
        $this->line("Paid with Gateway: " . $report['paid_with_gateway']);
        $this->line("Mixed Payments: " . $report['mixed_payments']);

        $this->info("\nFirst 5 details:");
        for ($i = 0; $i < min(5, count($details)); $i++) {
            $this->line("Transaction: " . $details[$i]['Transaction ID'] . " - Order: " . $details[$i]['Order ID'] . " - Status: " . $details[$i]['Order Status']);
        }

        return 0;
    }
}
