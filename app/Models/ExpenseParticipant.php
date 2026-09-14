<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseParticipant extends Model
{
    protected $table = 'expense_participants';

    protected $fillable = [
        'expense_id',
        'user_id',
        'share_paisa',
    ];

    protected $casts = [
        'share_paisa' => 'integer',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
