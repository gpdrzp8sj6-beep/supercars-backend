<?php

namespace App\Nova\Tools;

use Illuminate\Http\Request;
use Laravel\Nova\Tool;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Text;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use Illuminate\Support\Collection;

class TransactionSheetTool extends Tool
{
    /**
     * Perform any tasks for Nova Tools.
     *
     * @return array
     */
    public function boot()
    {
        //
    }

    /**
     * Build the view that renders the navigation links for the tool.
     *
     * @return array
     */
    public function menu(Request $request)
    {
        return [
            [
                'label' => 'Transaction Sheet Processor',
                'path' => '/transaction-sheet-processor',
                'icon' => 'document-text',
                'badge' => null,
            ],
        ];
    }

    /**
     * Build the tool's navigation menu.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function renderNavigation()
    {
        return [
            [
                'label' => 'Transaction Sheet Processor',
                'path' => '/transaction-sheet-processor',
                'icon' => 'document-text',
            ],
        ];
    }

    /**
     * Get the fields displayed by the tool.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function fields(Request $request)
    {
        return [
            File::make('Transaction Sheet', 'sheet')
                ->acceptedTypes('.csv')
                ->help('Upload the TP Transactions CSV file to process and match with orders.'),

            Text::make('Report', 'report')
                ->readonly()
                ->onlyOnDetail(),
        ];
    }

    /**
     * Handle the tool's form submission.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Laravel\Nova\Actions\ActionResponse
     */
    public function handle(Request $request)
    {
        $file = $request->file('sheet');

        if (!$file) {
            return \Laravel\Nova\Actions\ActionResponse::danger('Please upload a CSV file.');
        }

        // Store the file temporarily
        $path = $file->store('temp');

        try {
            // Parse CSV
            $csvData = $this->parseCsv(Storage::path($path));

            // Process and match with orders
            $report = $this->processTransactions($csvData);

            // Save to database
            $sheet = \App\Models\TransactionSheet::create([
                'filename' => $file->getClientOriginalName(),
                'summary' => $report,
                'details' => $report['details'],
            ]);

            // Clean up temp file
            Storage::delete($path);

            return \Laravel\Nova\Actions\ActionResponse::message('Transaction sheet processed and saved successfully.')
                ->redirect('/admin/resources/transaction-sheets/' . $sheet->id);

        } catch (\Exception $e) {
            Log::error('Error processing transaction sheet: ' . $e->getMessage());
            Storage::delete($path);
            return \Laravel\Nova\Actions\ActionResponse::danger('Error processing file: ' . $e->getMessage());
        }
    }

    private function parseCsv($filePath)
    {
        $data = [];
        if (($handle = fopen($filePath, "r")) !== FALSE) {
            $header = fgetcsv($handle, 1000, ",");
            while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $data[] = array_combine($header, $row);
            }
            fclose($handle);
        }
        return $data;
    }

    private function processTransactions($transactions)
    {
        $report = [
            'total_transactions' => count($transactions),
            'matched_orders' => 0,
            'total_revenue' => 0,
            'credit_used_total' => 0,
            'paid_with_credit' => 0,
            'paid_with_gateway' => 0,
            'mixed_payments' => 0,
        ];

        $details = [];

        foreach ($transactions as $transaction) {
            $checkoutId = $transaction['Merchant Transaction ID'] ?? '';
            $amount = (float) str_replace(',', '', $transaction['Amount'] ?? 0);
            $status = $transaction['Status'] ?? '';

            if ($status !== 'Success') {
                continue;
            }

            $report['total_revenue'] += $amount;

            // Try to match by order ID first (Merchant Transaction ID), then by checkoutId
            $order = Order::find($checkoutId);
            if (!$order) {
                $order = Order::where('checkoutId', 'like', '%' . $checkoutId . '%')->first();
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
                    'Amount' => $amount,
                    'Order ID' => $order->id,
                    'Order Status' => $order->status,
                    'User Email' => $order->user->email ?? '',
                    'Payment Method' => $paymentMethod,
                    'Credit Used' => $order->credit_used,
                    'Amount Paid' => $order->total,
                    'Original Total' => $order->original_total,
                    'Transaction Date' => $transaction['Date'] ?? '',
                ];
            } else {
                $details[] = [
                    'Transaction ID' => $transaction['Transaction ID'] ?? '',
                    'Merchant Transaction ID' => $checkoutId,
                    'Amount' => $amount,
                    'Order ID' => 'Not Found',
                    'Order Status' => 'N/A',
                    'User Email' => 'N/A',
                    'Payment Method' => 'N/A',
                    'Credit Used' => 0,
                    'Amount Paid' => 0,
                    'Original Total' => 0,
                    'Transaction Date' => $transaction['Date'] ?? '',
                ];
            }
        }

        $report['details'] = $details;

        return $report;
    }
}