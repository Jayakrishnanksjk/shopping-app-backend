<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Cancellation extends Model
{
    use HasFactory;

    protected $table = 'cancellations';

    protected $fillable = [
        'order_id',
        'scope',
        'items',
        'reason',
        'status',
    ];

    protected $casts = [
        'items' => 'json',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}