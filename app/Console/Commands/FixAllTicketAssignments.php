<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixAllTicketAssignments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:fix-all {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run all ticket assignment fixes in sequence';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $this->info('🎯 COMPREHENSIVE TICKET ASSIGNMENT FIX');
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info('This command will fix all ticket assignment issues:');
        $this->line('  1. Orders with giveaway records but empty ticket numbers');
        $this->line('  2. Orders with no giveaway records at all');
        $this->newLine();

        if (!$dryRun) {
            if (!$this->confirm('Do you want to proceed with the fixes?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $startTime = now();

        // Phase 1: Fix empty ticket numbers
        $this->info('=== PHASE 1: Fixing orders with empty giveaway records ===');
        $exitCode1 = $this->call('tickets:fix-empty-numbers', [
            '--dry-run' => $dryRun
        ]);

        $this->newLine();

        // Phase 2: Fix missing giveaway records
        $this->info('=== PHASE 2: Fixing orders with missing giveaway records ===');
        $exitCode2 = $this->call('tickets:fix-missing-records', [
            '--dry-run' => $dryRun
        ]);

        $this->newLine();

        // Phase 3: Verification
        $this->info('=== PHASE 3: Final Verification ===');
        $exitCode3 = $this->call('tickets:verify');

        $this->newLine();

        $duration = $startTime->diffInSeconds(now());

        if ($exitCode3 === Command::SUCCESS) {
            $this->info("🎉 COMPREHENSIVE FIX COMPLETED SUCCESSFULLY in {$duration} seconds!");
            if ($dryRun) {
                $this->warn('🔄 This was a dry run. Run without --dry-run to apply the actual fixes.');
            }
        } else {
            $this->error("⚠️  Some issues may still remain. Check the verification output above.");
        }

        return $exitCode3;
    }
}