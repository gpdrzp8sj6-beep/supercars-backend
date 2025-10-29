<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TransactionSheet;

class ReprocessTransactionSheets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transaction-sheets:reprocess {--sheet-id= : Specific sheet ID to reprocess}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reprocess existing transaction sheets to capture additional CSV fields';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sheetId = $this->option('sheet-id');

        if ($sheetId) {
            $sheets = TransactionSheet::where('id', $sheetId)->get();
        } else {
            $sheets = TransactionSheet::all();
        }

        $this->info("Found {$sheets->count()} transaction sheet(s) to reprocess");

        $progressBar = $this->output->createProgressBar($sheets->count());
        $progressBar->start();

        $processed = 0;
        $errors = 0;

        foreach ($sheets as $sheet) {
            try {
                // Re-run the CSV processing
                $sheet->processCsvFile();
                $sheet->save();

                $processed++;
            } catch (\Exception $e) {
                $this->error("Error processing sheet ID {$sheet->id}: {$e->getMessage()}");
                $errors++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Reprocessing complete:");
        $this->info("✅ Processed: {$processed}");
        if ($errors > 0) {
            $this->error("❌ Errors: {$errors}");
        }

        return $errors === 0 ? 0 : 1;
    }
}
