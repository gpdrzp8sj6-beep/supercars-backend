<?php

namespace App\Console\Commands;

use App\Models\Giveaway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FixGiveaway678434Images extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'giveaways:fix-678434-images {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix image URLs for giveaway 678434 to use correct domain';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $giveawayId = 678434;
        $giveaway = Giveaway::find($giveawayId);

        if (!$giveaway) {
            $this->error("Giveaway {$giveawayId} not found");
            return Command::FAILURE;
        }

        $this->info("Giveaway: {$giveaway->title} (ID: {$giveawayId})");
        $this->info("Current images: " . json_encode($giveaway->images, JSON_PRETTY_PRINT));

        // Get available images from storage
        $availableImages = Storage::disk('public')->files('images');
        $baseUrl = config('app.url') . '/storage/';

        if (empty($availableImages)) {
            $this->error('No images found in storage/images directory');
            return Command::FAILURE;
        }

        $this->info("Found " . count($availableImages) . " images in storage");

        // Create new image URLs using current APP_URL
        $newImageUrls = collect($availableImages)->take(6)->map(function($image) use ($baseUrl) {
            return $baseUrl . $image;
        })->toArray();

        $this->info("New image URLs that will be set:");
        foreach ($newImageUrls as $index => $url) {
            $this->line("  " . ($index + 1) . ": {$url}");
        }

        if (!$dryRun) {
            $giveaway->update(['images' => $newImageUrls]);
            $this->info("Giveaway {$giveawayId}: Images updated successfully!");
        } else {
            $this->info("DRY RUN: Would update images for giveaway {$giveawayId}");
        }

        return Command::SUCCESS;
    }
}