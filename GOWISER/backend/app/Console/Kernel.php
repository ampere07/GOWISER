<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // ===================================================================
        // BILLING GENERATION (DEDICATED CRON JOB)
        // ===================================================================
        
        // Generate daily billings at 1:00 AM every day
        // Uses: EnhancedBillingGenerationServiceWithNotifications
        // Dependencies: BillingNotificationService, EmailQueueService, 
        //               GoogleDrivePdfGenerationService, ItexmoSmsService
        // Logs: storage/logs/billing/billinggeneration.log
        $schedule->command('cron:generate-daily-billings')
                 ->dailyAt('01:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Billing generation cron completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Billing generation cron failed');
                 });

        // ===================================================================
        // PREPAID PRE-EXPIRY WARNINGS (PREPAID ONLY — NOT PART OF BILLING)
        // ===================================================================

        // Warn prepaid customers whose service period is about to lapse, so they can renew before
        // anything is restricted. SMS only; raises no SOA and no invoice.
        //
        // Deliberately its own command rather than a step inside cron:generate-daily-billings.
        // It concerns prepaid accounts only and produces no bill, so folding it into the billing
        // run meant a failure in either landed in the other's log, and re-running the warning was
        // impossible without also re-entering bill generation.
        //
        // 01:30, after the billing run rather than alongside it, so the two never contend for the
        // same accounts. Anything before 08:00 works: the notice is queued with a time_sent of
        // 08:00 Asia/Manila and is delivered then by cron:process-email-queue.
        //
        // Safe to repeat. SmsQueueService deduplicates on (account, contact, message, time_sent),
        // and the scan marks billing_accounts.prepaid_pre_expiry_notified_for once a warning has
        // actually gone out — so a second run on the same day queues nothing.
        // Logs: storage/logs/billing/billing.log (the 'billing' channel)
        $schedule->command('billing:notify-prepaid-pre-expiry')
                 ->dailyAt('01:30')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Prepaid pre-expiry warnings completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Prepaid pre-expiry warnings failed');
                 });

        // ===================================================================
        // BILLING NOTIFICATIONS
        // ===================================================================

        // Send overdue notices at 10:00 AM for invoices 1 day past due
        // Uses: BillingNotificationService
        // Dependencies: EmailQueueService, GoogleDrivePdfGenerationService, ItexmoSmsService
        $schedule->command('billing:send-overdue --days=1')
                 ->dailyAt('10:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Overdue notices sent successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Overdue notices sending failed');
                 });

        // ===================================================================
        // OVERDUE & DISCONNECTION NOTICES
        // ===================================================================

        // Note: Overdue and Disconnection notices are now generated and sent 
        // as part of the 'cron:generate-daily-billings' command defined above.
        // The previous standalone commands 'cron:process-overdue-notifications'
        // and 'cron:process-disconnection-notices' have been deprecated and removed.

        // ===================================================================
        // AUTO DISCONNECT & PULLOUT
        // ===================================================================

        // Automatically disconnect overdue accounts and create pullout requests
        // Runs at 2:00 AM daily (after billing generation)
        // Uses: AutoDisconnectService, ManualRadiusOperationsService
        // Dependencies: BillingConfig for DC fee and offset settings
        // Disconnects accounts X days overdue (configurable via billing_config.disconnection_day)
        // Creates pullout requests for accounts Y days overdue (configurable via billing_config.pullout_offset)
        // Logs: storage/logs/disconnectionday.log
        $schedule->command('cron:auto-disconnect-pullout')
                 ->dailyAt('02:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Auto disconnect/pullout completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Auto disconnect/pullout failed');
                 });

        // ===================================================================
        // VIP ACCOUNTS EXPIRATION CHECK
        // ===================================================================

        // Check VIP accounts for expiration daily at midnight
        // Uses: ManualRadiusOperationsService
        // Logs: storage/logs/vipChecker.log
        $schedule->command('vip:check-expiration')
                 ->dailyAt('00:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('VIP expiration check completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('VIP expiration check failed');
                 });

        // ===================================================================
        // EMAIL QUEUE PROCESSING (DEDICATED CRON JOBS)
        // ===================================================================

        // Process pending emails every minute
        // Uses: EmailQueueService via dedicated cron command
        // Dependencies: ResendEmailService
        // Processes up to 50 emails per run
        $schedule->command('cron:process-email-queue')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Email queue cron completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Email queue cron failed');
                 });

        // Retry failed emails every 5 minutes
        // Uses: EmailQueueService via dedicated cron command
        // Dependencies: ResendEmailService
        // Retries up to 20 failed emails with max 3 attempts
        $schedule->command('cron:retry-failed-emails')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Failed emails retry cron completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Failed emails retry cron failed');
                 });

        // ===================================================================
        // AUTOMATED REPORTS QUEUING
        // ===================================================================

        // Generate due scheduled reports as PDFs and queue them to the email
        // queue, then sweep stale attachments.
        // Uses: ReportDispatchService -> ReportPdfService, ReportMetricsService
        // Runs every minute so a report can be scheduled to any HH:MM.
        // Exactly-once delivery: each (report, occurrence) pair is claimed in
        // report_dispatches under a UNIQUE index before any email is queued, so
        // overlapping or repeated runs cannot send a report twice.
        // Late runs: an occurrence stays eligible for reports.catch_up_minutes
        // after its scheduled time, so one missed tick does not skip a report
        // for a whole day/month/year.
        // The queued emails are then sent by 'cron:process-email-queue' above.
        // Logs: storage/logs/reports/reports.log
        $schedule->command('reports:queue')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     // \Illuminate\Support\Facades\Log::info('Reports queue cron completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Reports queue cron failed');
                 });

        // ===================================================================
        // RADIUS STATUS SYNC
        // ===================================================================

        // Sync RADIUS user status and sessions every 2 minutes
        // Uses: RadiusStatusSyncService
        // Dependencies: RadiusConfig, BillingAccounts, TechnicalDetails, OnlineStatus
        // Logs: storage/logs/radiussync/radiussync.log
        $schedule->command('cron:sync-radius-status')
                 ->everyTwoMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('RADIUS status sync completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('RADIUS status sync failed');
                 });

        // ===================================================================
        // RADIUS OPERATION RETRY QUEUE
        // ===================================================================

        // Retry failed RADIUS operations every 2 minutes
        // Uses: RadiusQueueService, ManualRadiusOperationsService
        // Processes up to 20 pending items per run with exponential backoff
        // Logs: storage/logs/radiusrelated.log
        $schedule->command('cron:process-radius-queue')
                 ->everyTwoMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('RADIUS queue processing completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('RADIUS queue processing failed');
                 });

        // ===================================================================
        // PAYMENT PROCESSING
        // ===================================================================

        // Process pending payments every 2 minutes
        // Uses: PaymentWorkerService
        // Dependencies: Xendit API
        $schedule->command('payments:process')
                 ->everyTwoMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Payment processing completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Payment processing failed');
                 });

        // Retry failed payments daily at 2:00 PM
        // Uses: PaymentWorkerService
        // Dependencies: Xendit API
        $schedule->command('payments:retry-failed')
                 ->dailyAt('14:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Failed payments retry completed');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Failed payments retry failed');
                 });

        // ===================================================================
        // PREPAID PLAN CHANGES
        // ===================================================================

        // Apply prepaid plan changes queued by a payment, once the customer's current prepaid
        // period lapses. Uses: PrepaidPlanChangeService, ManualRadiusOperationsService
        // Hourly (not daily) because prepaid_expires_at carries a time-of-day, so an account
        // that lapses mid-afternoon switches that afternoon rather than at the next midnight.
        $schedule->command('prepaid:apply-pending-plans')
                 ->hourly()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Prepaid pending plan application failed');
                 });

        // ===================================================================
        // MAINTENANCE & CLEANUP
        // ===================================================================

        // Cleanup worker locks every hour
        // Prevents stale locks from blocking payment processing
        $schedule->command('worker:cleanup-locks')
                 ->hourly()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Worker locks cleaned up');
                 });

        // ===================================================================
        // TECHNICIAN LIVE LOCATION
        // ===================================================================
        // The stale-location sweep (cron:mark-stale-locations) is invoked directly
        // from the system crontab every minute, e.g.:
        //   * * * * * cd /home/gowiser/web/backend.gowiser.ph/public_html && /usr/bin/php artisan cron:mark-stale-locations
        // so it is intentionally NOT registered with the Laravel scheduler here.

        // ===================================================================
        // OPTIONAL: Additional hourly billing checks during business hours
        // Uncomment if you want additional billing generation checks
        // ===================================================================
        // $schedule->command('billing:generate-daily')
        //          ->hourly()
        //          ->between('08:00', '18:00')
        //          ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}



