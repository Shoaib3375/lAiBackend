<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $keyType  = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'id', 'name', 'email', 'github_id', 'github_token',
        'github_login', 'avatar', 'plan', 'stripe_id', 'subscription_id',
    ];

    protected $hidden = ['github_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) \Str::uuid());
    }

    /* ── Relationships ── */
    public function repositories() {
        return $this->hasMany(Repository::class);
    }
    public function rulesets() {
        return $this->hasMany(Ruleset::class);
    }
    public function usageLogs() {
        return $this->hasMany(UsageLog::class);
    }
    public function defaultRuleset() {
        return $this->hasOne(Ruleset::class)->where('is_default', true);
    }

    /* ── Helpers ── */
    public function isOnPlan(string $plan): bool {
        return $this->plan === $plan;
    }
    public function isPro(): bool {
        return in_array($this->plan, ['pro', 'team']);
    }
    public function monthlyPrLimit(): int {
        return match($this->plan) {
            'pro'  => 500,
            'team' => 9999,
            default => 50,
        };
    }
    public function decryptedGithubToken(): string {
        return decrypt($this->github_token);
    }
}
