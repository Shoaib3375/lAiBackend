<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class UsageLog extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'tokens_used', 'prs_reviewed', 'month',
    ];

    protected $casts = ['month' => 'date'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) \Str::uuid());
    }

    public function user() { return $this->belongsTo(User::class); }
}
