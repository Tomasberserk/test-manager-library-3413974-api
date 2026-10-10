<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Book extends Model
{
    use SoftDeletes;

    protected $table = 'library.book';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    const DELETED_AT = 'deleted_at';

    protected $fillable = [
        'id',
        'title',
        'author',
        'isbn',
        'category_id',
        'total_copies',
        'available_copies',
        'cover_key',
        'deleted_at',
    ];

    protected $casts = [
        'total_copies' => 'integer',
        'available_copies' => 'integer',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            if (!empty($model->title)) {
                $model->title = trim($model->title);
            }
            if (!empty($model->author)) {
                $model->author = trim($model->author);
            }
            if (!empty($model->isbn)) {
                $model->isbn = trim($model->isbn);
            }
            if (!isset($model->available_copies)) {
                $model->available_copies = $model->total_copies;
            }
        });
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    public function loanItems()
    {
        return $this->hasMany(LoanItem::class, 'book_id', 'id');
    }
}
