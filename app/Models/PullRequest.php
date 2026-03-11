<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PullRequest extends Model
{
    protected $keyType = 'string';
    public $incrementing = 'false';

    protected $fillable = [
        'id', 'repository_id', 'pr_number', 'title', 'author',
        'pr_url', 'commit_sha', 'status', 'health_score', 'ai_summary',
    ];

    protected $casts = ['health_score' => 'integer'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) \Str::uuid());
    }

    public function repository()     { return $this->belongsTo(Repository::class); }
    public function reviewComments(){ return $this->hasMany(ReviewComment::class); }

    public function errorCount(): int {
        return $this->reviewComments()->where('severity', 'error')->count();
    }
    public function isDone(): bool {
        return $this->status === 'done';
    }
}
