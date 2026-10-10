<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Loan extends Model
{
    protected $table = 'library.loan';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'member_id',
        'loaned_by_user_id',
        'loaned_at',
    ];

    protected $casts = [
        'loaned_at' => 'datetime',
    ];

    protected $appends = [
        'status',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            if (empty($model->loaned_at)) {
                $model->loaned_at = Carbon::now('UTC');
            }
        });
    }

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }

    public function loanedByUser()
    {
        return $this->belongsTo(User::class, 'loaned_by_user_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(LoanItem::class, 'loan_id', 'id');
    }

    /**
     * Calculate loan status on the fly (open, overdue, closed)
     */
    public function getStatusAttribute(): string
    {
        return $this->calculateStatus();
    }

    public function calculateStatus(?Carbon $now = null): string
    {
        $now = $now ?: Carbon::now('UTC');
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty()) {
            return 'open';
        }

        $allReturned = true;
        $hasOverdue = false;

        foreach ($items as $item) {
            if ($item->returned_at === null) {
                $allReturned = false;
                $dueAt = Carbon::parse($item->due_at, 'UTC');
                if ($dueAt->lt($now)) {
                    $hasOverdue = true;
                }
            }
        }

        if ($allReturned) {
            return 'closed';
        }

        if ($hasOverdue) {
            return 'overdue';
        }

        return 'open';
    }
}
