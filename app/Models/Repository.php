<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Repository extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'ruleset_id', 'repo_full_name',
        'provider', 'webhook_secret', 'webhook_id', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) \Str::uuid());
    }

    public function user()        { return $this->belongsTo(User::class); }
    public function ruleset()     { return $this->belongsTo(Ruleset::class); }
    public function pullRequests(){ return $this->hasMany(PullRequest::class); }

    public function ownerLogin(): string {
        return explode('/', $this->repo_full_name)[0];
    }
    public function repoName(): string {
        return explode('/', $this->repo_full_name)[1];
    }
}
