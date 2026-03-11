<?php
namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class WeeklyDigestNotification extends Notification
{
    public function __construct(public readonly array $stats) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your PRDock Weekly Summary')
            ->greeting("Here's your week in review 📊")
            ->line("PRs reviewed this week: {$this->stats['prs_reviewed']}")
            ->line("Issues caught: {$this->stats['issues_found']}")
            ->line("Average health score: {$this->stats['avg_score']}/100")
            ->action('View Dashboard', env('FRONTEND_URL') . '/dashboard');
    }
}

/*
 * Schedule the weekly digest in app/Console/Kernel.php:
 *
 * $schedule->call(function () {
 *     User::all()->each(function ($user) {
 *         $stats = app(UsageService::class)->weeklyStats($user);
 *         $user->notify(new WeeklyDigestNotification($stats));
 *     });
 * })->weeklyOn(1, '08:00'); // Every Monday 8am
 */
