<?php

namespace App\Models;

use App\Enums\RecurrenceFrequency;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Task extends Model
{
    protected $fillable = [
        'project_id',
        'parent_task_id',
        'task_template_id',
        'title',
        'description',
        'type',
        'status',
        'assigned_to',
        'due_date',
        'time_spent_minutes',
        'rejection_reason',
        'recurrence_frequency',
        'is_recurrence_source',
        'recurrence_source_id',
        'next_occurrence_date',
        'created_by',
        'approved_by',
        'submitted_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'due_date' => 'date',
            'next_occurrence_date' => 'date',
            'time_spent_minutes' => 'integer',
            'parent_task_id' => 'integer',
            'is_recurrence_source' => 'boolean',
            'recurrence_frequency' => RecurrenceFrequency::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recurrenceSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrence_source_id');
    }

    public function recurrenceInstances(): HasMany
    {
        return $this->hasMany(self::class, 'recurrence_source_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', TaskStatus::Approved->value);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', TaskStatus::Submitted->value);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('project_id', $user->accessibleProjectIds() ?: [0]);
    }
}
