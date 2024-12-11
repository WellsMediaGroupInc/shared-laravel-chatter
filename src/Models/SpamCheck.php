<?php

namespace DevDojo\Chatter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SpamCheck extends Model
{
    protected $table = 'chatter_spam_checks';
    protected $guarded = ['id'];
    protected $casts = [
        'metadata' => 'array',
        'is_spam' => 'boolean',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function getIncrementing()
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }

    public function checkable()
    {
        return $this->morphTo();
    }
}