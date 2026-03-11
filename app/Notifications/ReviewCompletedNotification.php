<?php
namespace App\Notifications;

use App\Models\PullRequest;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ReviewCompletedNotification extends Notification
{
    public function __construct(public readonly PullRequest $pr) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $errors   = $this->pr->errorCount();
        $repoName = $this->pr->repository->repo_full_name;

        return (new MailMessage)
            ->subject("PRDock reviewed PR #{$this->pr->pr_number} in {$repoName}")
            ->greeting("Review complete 🔍")
            ->line("PR: {$this->pr->title}")
            ->line("Health score: {$this->pr->health_score}/100")
            ->line("Issues found: {$errors} error(s)")
            ->action('View Full Review', env('FRONTEND_URL') . "/prs/{$this->pr->id}")
            ->line('Thank you for using PRDock.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'pr_id'       => $this->pr->id,
            'pr_number'   => $this->pr->pr_number,
            'title'       => $this->pr->title,
            'health_score' => $this->pr->health_score,
            'repo'        => $this->pr->repository->repo_full_name,
        ];
    }
}
