<?php

namespace App\Console\Commands;

use App\Mail\OrderCompleted;
use App\Mail\OtpMail;
use App\Mail\PasswordResetOtpMail;
use App\Mail\WelcomeUser;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:emails {email} {--type=all : Type of email to test (all, order, otp, password-reset, welcome)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test all email types by sending them to a specified email address';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $type = $this->option('type');

        $this->info("🚀 Testing emails - sending to: {$email}");
        $this->info("📧 Email type: {$type}");
        $this->info("🔍 Debug: Email argument received: '{$email}'");
        $this->newLine();

        $sentCount = 0;

        try {
            // Test Order Completed Email
            if ($type === 'all' || $type === 'order') {
                $this->info("📦 Testing Order Completed Email...");

                $order = Order::whereHas('giveaways')->latest()->first();

                if ($order) {
                    Mail::to($email)->send(new OrderCompleted($order));
                    $this->info("✅ Order Completed Email sent successfully!");
                    $sentCount++;
                } else {
                    $this->warn("⚠️  No order with giveaways found. Skipping order email test.");
                }
            }

            // Test OTP Email
            if ($type === 'all' || $type === 'otp') {
                $this->info("🔐 Testing OTP Verification Email...");

                $otp = rand(100000, 999999); // Generate 6-digit OTP
                Mail::to($email)->send(new OtpMail($otp));
                $this->info("✅ OTP Email sent successfully! (OTP: {$otp})");
                $sentCount++;
            }

            // Test Password Reset OTP Email
            if ($type === 'all' || $type === 'password-reset') {
                $this->info("🔑 Testing Password Reset OTP Email...");

                $otp = rand(100000, 999999); // Generate 6-digit OTP
                Mail::to($email)->send(new PasswordResetOtpMail($otp));
                $this->info("✅ Password Reset OTP Email sent successfully! (OTP: {$otp})");
                $sentCount++;
            }

            // Test Welcome Email
            if ($type === 'all' || $type === 'welcome') {
                $this->info("🎉 Testing Welcome Email...");

                $user = User::latest()->first();

                if ($user) {
                    Mail::to($email)->send(new WelcomeUser($user));
                    $this->info("✅ Welcome Email sent successfully!");
                    $sentCount++;
                } else {
                    $this->warn("⚠️  No users found. Skipping welcome email test.");
                }
            }

            $this->newLine();
            $this->info("🎉 Email testing completed!");
            $this->info("📊 Total emails sent: {$sentCount}");

            if ($sentCount > 0) {
                $this->info("📬 Check your inbox at: {$email}");
            }

        } catch (\Exception $e) {
            $this->error("❌ Error sending emails: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
