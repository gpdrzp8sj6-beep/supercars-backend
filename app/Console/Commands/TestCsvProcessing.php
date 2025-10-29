<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TransactionSheet;
use App\Models\Order;

class TestCsvProcessing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:csv-processing {file?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test CSV processing logic for transaction sheets';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file') ?? 'test/test_transactions.csv';

        $this->info("Testing CSV processing with file: {$filePath}");

        // Create a test transaction sheet
        $transactionSheet = new TransactionSheet([
            'filename' => basename($filePath),
            'file_path' => $filePath,
        ]);

        // Process the CSV
        $transactionSheet->processCsvFile();

        $this->info('Processing complete!');
        $this->info('Summary: ' . json_encode($transactionSheet->summary, JSON_PRETTY_PRINT));

        // Check if orders exist
        $this->info("\nChecking orders:");
        $orders = Order::whereIn('id', [12345, 12346, 99999])->get();
        foreach ($orders as $order) {
            $this->info("Order {$order->id} exists");
        }

        return 0;
    }
}
