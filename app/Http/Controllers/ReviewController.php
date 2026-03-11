<?php
namespace App\Http\Controllers;

use App\Models\{PullRequest, ReviewComment};
use App\Services\UsageService;
use App\Notifications\ReviewCompletedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    public function __construct(private UsageService $usage) {}

    /**
     * POST /api/internal/reviews/store
     * Called by Python worker after AI review is complete.
     * Protected by internal shared secret header (not Sanctum).
     *
     * Expected payload:
     * {
     *   "pr_id": "uuid",
     *   "status": "done" | "failed",
     *   "health_score": 82,
     *   "ai_summary": "This PR adds...",
     *   "comments": [
     *     { "file_path": "app/Foo.php", "line_number": 14,
     *       "severity": "error", "body": "...", "github_comment_id": 123 }
     *   ]
     * }
     */
    public function store(Request $request)
    {
        // Verify internal secret from Python worker
        if ($request->header('X-Internal-Secret') !== config('app.internal_secret')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'pr_id'        => 'required|uuid|exists:pull_requests,id',
            'status'       => 'required|in:done,failed',
            'health_score' => 'nullable|integer|min:0|max:100',
            'ai_summary'   => 'nullable|string|max:2000',
            'comments'     => 'array',
            'comments.*.file_path'        => 'required|string',
            'comments.*.line_number'      => 'required|integer',
            'comments.*.severity'         => 'required|in:error,warning,info',
            'comments.*.body'             => 'required|string',
            'comments.*.github_comment_id' => 'nullable|integer',
        ]);

        $pr = PullRequest::findOrFail($data['pr_id']);

        DB::transaction(function () use ($pr, $data) {
            // Save review comments
            if (!empty($data['comments'])) {
                $comments = array_map(fn($c) => array_merge($c, [
                    'id'             => (string) \Str::uuid(),
                    'pull_request_id' => $pr->id,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]), $data['comments']);

                ReviewComment::insert($comments); // bulk insert
            }

            // Update PR status
            $pr->update([
                'status'       => $data['status'],
                'health_score' => $data['health_score'] ?? null,
                'ai_summary'   => $data['ai_summary'] ?? null,
            ]);

            // Track usage
            $this->usage->increment($pr->repository->user);

            // Notify user
            $pr->repository->user->notify(
                new ReviewCompletedNotification($pr)
            );
        });

        return response()->json(['status' => 'saved']);
    }
}
