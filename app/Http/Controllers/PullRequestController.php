<?php
namespace App\Http\Controllers;

use App\Models\PullRequest;
use App\Jobs\DispatchReviewJob;
use App\Services\UsageService;
use Illuminate\Http\Request;

class PullRequestController extends Controller
{
    public function __construct(private UsageService $usage) {}

    /**
     * GET /api/pull-requests
     * List all PRs for the authenticated user
     * Supports: ?repo_id=, ?status=, ?page=
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = PullRequest::whereHas('repository', fn($q) =>
        $q->where('user_id', $user->id))
            ->with(['repository:id,repo_full_name,provider'])
            ->withCount([
                'reviewComments',
                'reviewComments as error_count' => fn($q) => $q->where('severity', 'error'),
            ]);

        // Filters
        if ($request->filled('repo_id')) {
            $query->where('repository_id', $request->repo_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('author')) {
            $query->where('author', $request->author);
        }

        return $query->latest()->paginate(20);
    }

    /**
     * GET /api/pull-requests/{pr}
     * Single PR with all review comments grouped by file
     */
    public function show(PullRequest $pullRequest)
    {
        $this->authorizeRepoAccess($pullRequest);

        $pullRequest->load([
            'repository:id,repo_full_name,provider',
            'reviewComments' => fn($q) => $q->orderBy('file_path')->orderBy('line_number'),
        ]);

        // Group comments by file for easy frontend rendering
        $commentsByFile = $pullRequest->reviewComments
            ->groupBy('file_path');

        return response()->json([
            'pr'              => $pullRequest->makeHidden('reviewComments'),
            'comments_by_file' => $commentsByFile,
            'summary'         => [
                'total'    => $pullRequest->reviewComments->count(),
                'errors'   => $pullRequest->reviewComments->where('severity', 'error')->count(),
                'warnings' => $pullRequest->reviewComments->where('severity', 'warning')->count(),
                'info'     => $pullRequest->reviewComments->where('severity', 'info')->count(),
            ],
        ]);
    }

    /**
     * POST /api/pull-requests/{pr}/re-review
     * Manually trigger a fresh review (re-queues the job)
     */
    public function reReview(PullRequest $pullRequest)
    {
        $this->authorizeRepoAccess($pullRequest);

        // Check usage limit before re-queuing
        if (!$this->usage->canReview(auth()->user())) {
            return response()->json(['error' => 'Monthly PR limit reached'], 429);
        }

        // Wipe old comments — fresh review
        $pullRequest->reviewComments()->delete();
        $pullRequest->update(['status' => 'pending', 'health_score' => null, 'ai_summary' => null]);

        DispatchReviewJob::dispatch($pullRequest, $pullRequest->repository)
            ->onQueue('reviews');

        return response()->json(['status' => 'queued']);
    }

    /** Ensure PR belongs to authenticated user */
    private function authorizeRepoAccess(PullRequest $pr): void
    {
        if ($pr->repository->user_id !== auth()->id()) {
            abort(403);
        }
    }
}
