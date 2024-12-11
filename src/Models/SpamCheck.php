<?php

namespace DevDojo\Chatter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SpamCheck extends Model
{
    use HasUuids;

    protected $table = 'chatter_spam_checks';
    protected $guarded = ['id'];
    protected $casts = [
        'metadata' => 'array',
        'is_spam' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function checkable()
    {
        return $this->morphTo();
    }
} 