<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Ruleset extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'name', 'rules', 'language', 'is_default',
    ];

    protected $casts = [
        'rules'      => 'array',   // auto JSON encode/decode
        'is_default' => 'boolean',
    ];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) \Str::uuid());
    }

    public function user()         { return $this->belongsTo(User::class); }
    public function repositories(){ return $this->hasMany(Repository::class); }
}
