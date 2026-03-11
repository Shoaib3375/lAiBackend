<?php
namespace App\Services;

use App\Models\{User, UsageLog};

class UsageService
{
    /** Check if user can review another PR this month */
    public function canReview(User $user): bool
    {
        return $this->monthlyUsed($user) < $user->monthlyPrLimit();
    }

    /** How many PRs reviewed this month */
    public function monthlyUsed(User $user): int
    {
        return (int) $user->usageLogs()
            ->whereYear('month', now()->year)
            ->whereMonth('month', now()->month)
            ->sum('prs_reviewed');
    }

    /** Increment PR count after a review completes */
    public function increment(User $user, int $tokens = 0): void
    {
        $month = now()->startOfMonth()->toDateString();

        UsageLog::updateOrCreate(
            ['user_id' => $user->id, 'month' => $month],
            [] // creates if not exists
        );

        // Atomic increment to avoid race conditions
        UsageLog::where('user_id', $user->id)
            ->where('month', $month)
            ->increment('prs_reviewed');

        if ($tokens > 0) {
            UsageLog::where('user_id', $user->id)
                ->where('month', $month)
                ->increment('tokens_used', $tokens);
        }
    }

    /** Usage summary for billing page */
    public function summary(User $user): array
    {
        return [
            'used'      => $this->monthlyUsed($user),
            'limit'     => $user->monthlyPrLimit(),
            'remaining' => max(0, $user->monthlyPrLimit() - $this->monthlyUsed($user)),
            'percent'   => round($this->monthlyUsed($user) / $user->monthlyPrLimit() * 100),
            'plan'      => $user->plan,
        ];
    }
}
