<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ReviewComment extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'pull_request_id', 'file_path', 'line_number',
        'severity', 'body', 'github_comment_id',
    ];

    protected $casts = ['line_number' => 'integer', 'github_comment_id' => 'integer'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) \Str::uuid());
    }

    public function pullRequest() { return $this->belongsTo(PullRequest::class); }
}
