<?php
namespace App\Http\Controllers;

use App\Models\{PullRequest, ReviewComment};
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** GET /api/dashboard/stats */
    public function stats()
    {
        $user    = auth()->user();
        $repoIds = $user->repositories->pluck('id');

        $prsThisMonth = PullRequest::whereIn('repository_id', $repoIds)
            ->whereMonth('created_at', now())
            ->whereYear('created_at', now());

        $allPrs = PullRequest::whereIn('repository_id', $repoIds);

        $allComments = ReviewComment::whereHas('pullRequest', fn($q) =>
        $q->whereIn('repository_id', $repoIds));

        return response()->json([
            'prs_this_month'   => (clone $prsThisMonth)->count(),
            'prs_total'        => (clone $allPrs)->count(),
            'issues_found'     => (clone $allComments)->count(),
            'errors_found'     => (clone $allComments)->where('severity', 'error')->count(),
            'avg_health_score' => round((clone $allPrs)->whereNotNull('health_score')->avg('health_score')),
            'repos_connected'  => $user->repositories()->where('is_active', true)->count(),
            'plan'             => $user->plan,
            'prs_limit'        => $user->monthlyPrLimit(),
            'prs_used'         => $user->usageLogs()->whereMonth('created_at', now())->sum('prs_reviewed'),
        ]);
    }

    /** GET /api/dashboard/activity */
    public function activity()
    {
        $repoIds = auth()->user()->repositories->pluck('id');

        $prs = PullRequest::whereIn('repository_id', $repoIds)
            ->with('repository:id,repo_full_name')
            ->withCount('reviewComments')
            ->latest()
            ->limit(25)
            ->get(['id', 'repository_id', 'pr_number', 'title', 'author',
                'status', 'health_score', 'pr_url', 'created_at']);

        return response()->json($prs);
    }

    /** GET /api/dashboard/top-issues */
    public function topIssues()
    {
        $repoIds = auth()->user()->repositories->pluck('id');

        // Most common issue patterns (first 8 words of body as category)
        $issues = ReviewComment::whereHas('pullRequest', fn($q) =>
        $q->whereIn('repository_id', $repoIds))
            ->select('severity', 'file_path', DB::raw('COUNT(*) as count'))
            ->groupBy('severity', 'file_path')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $bySeverity = ReviewComment::whereHas('pullRequest', fn($q) =>
        $q->whereIn('repository_id', $repoIds))
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->get();

        return response()->json([
            'top_files'   => $issues,
            'by_severity' => $bySeverity,
        ]);
    }
}
