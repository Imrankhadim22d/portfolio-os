<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    public const TYPE_PROJECT = 'project';
    public const TYPE_PERSONAL = 'personal';

    protected $fillable = [
        'project_id',
        'expense_type',
        'owner_user_id',
        'is_shared',
        'expense_category_id',
        'amount_paisa',
        'currency',
        'description',
        'expense_date',
        'source_type',
        'source_id',
        'created_by',
        'updated_by',
        'notes',
        'is_paid',
        'paid_at',
        'receipt_path',
        'receipt_original_name',
        'recurring_expense_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_paisa' => 'integer',
            'expense_date' => 'date',
            'expense_type' => 'string',
            'owner_user_id' => 'integer',
            'is_shared' => 'boolean',
            'is_paid' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ExpenseAllocation::class);
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id');
    }

    public function amountFormatted(): string
    {
        return Money::formatted((int) $this->amount_paisa, $this->currency);
    }

    /**
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $ids = $user->accessibleProjectIds() ?: [0];

        return $query->where(function (Builder $q) use ($ids, $user) {
            $q->whereIn('project_id', $ids)
                ->orWhere('is_shared', true)
                ->orWhere(function (Builder $personal) use ($user) {
                    $personal->where('expense_type', self::TYPE_PERSONAL)
                        ->where('owner_user_id', $user->id);
                });
        });
    }

    /**
     * Direct (non-shared) expenses for a project.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeDirectForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId)->where('is_shared', false);
    }

    /**
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeShared(Builder $query): Builder
    {
        return $query->where('is_shared', true);
    }
}
