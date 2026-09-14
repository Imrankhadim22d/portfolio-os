<?php

namespace App\Livewire\Tasks;

use App\Enums\TaskStatus;
use App\Models\Media;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\TaskWorkflowService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Task')]
class Show extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Task $task;

    public string $commentBody = '';

    public string $time_spent_minutes = '0';

    public string $assigned_to = '';

    public string $due_date = '';

    public string $rejection_reason = '';

    public bool $showReject = false;

    public bool $showSubtaskForm = false;

    public ?int $editingSubtaskId = null;

    public string $subtaskTitle = '';

    public string $subtaskDescription = '';

    public string $subtaskAssignedTo = '';

    public string $subtaskDueDate = '';

    public string $subtaskStatus = TaskStatus::Assigned->value;

    public $evidence;

    public function mount(Task $task): void
    {
        $this->authorize('view', $task);
        $this->task = $task->load(['project', 'assignee', 'comments.user', 'media', 'template', 'children.assignee', 'children.project']);
        $this->time_spent_minutes = (string) $task->time_spent_minutes;
        $this->assigned_to = (string) ($task->assigned_to ?? '');
        $this->due_date = $task->due_date?->format('Y-m-d') ?? '';
        $this->subtaskStatus = TaskStatus::Assigned->value;
    }

    public function saveMeta(): void
    {
        $this->authorize('update', $this->task);

        $validated = $this->validate([
            'time_spent_minutes' => ['required', 'integer', 'min:0', 'max:100000'],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $data = [
            'time_spent_minutes' => (int) $validated['time_spent_minutes'],
            'due_date' => $validated['due_date'] ?: null,
        ];

        if (Auth::user()->can('assign', $this->task)) {
            $data['assigned_to'] = $validated['assigned_to'] ?: null;
        }

        $this->task->update($data);
        $this->task->refresh();
        $this->dispatch('toast', message: 'Task updated.', tone: 'success');
    }

    public function deleteTask(): void
    {
        $this->authorize('delete', $this->task);

        if ($this->task->children()->exists()) {
            $this->addError('task', 'Cannot delete a task that still has subtasks. Delete the subtasks first or move them to a new parent task.');
            return;
        }

        foreach ($this->task->media as $media) {
            Storage::disk($media->disk)->delete($media->path);
            $media->delete();
        }

        $this->task->delete();

        $this->dispatch('toast', message: 'Task deleted.', tone: 'success');
    }

    public function openSubtaskForm(): void
    {
        $this->authorize('create', Task::class);
        $this->showSubtaskForm = true;
        $this->resetSubtaskForm();
    }

    public function editSubtask(int $subtaskId): void
    {
        $subtask = Task::query()->findOrFail($subtaskId);
        $this->authorize('update', $subtask);

        $this->editingSubtaskId = $subtask->id;
        $this->subtaskTitle = $subtask->title;
        $this->subtaskDescription = (string) $subtask->description;
        $this->subtaskAssignedTo = (string) ($subtask->assigned_to ?? '');
        $this->subtaskDueDate = $subtask->due_date?->format('Y-m-d') ?? '';
        $this->subtaskStatus = $subtask->status->value;
        $this->showSubtaskForm = true;
    }

    public function resetSubtaskForm(): void
    {
        $this->editingSubtaskId = null;
        $this->subtaskTitle = '';
        $this->subtaskDescription = '';
        $this->subtaskAssignedTo = '';
        $this->subtaskDueDate = '';
        $this->subtaskStatus = TaskStatus::Assigned->value;
    }

    public function cancelSubtaskForm(): void
    {
        $this->showSubtaskForm = false;
        $this->resetSubtaskForm();
    }

    public function saveSubtask(): void
    {
        $isEditing = $this->editingSubtaskId !== null;

        $validated = $this->validate([
            'subtaskTitle' => ['required', 'string', 'max:255'],
            'subtaskDescription' => ['nullable', 'string', 'max:10000'],
            'subtaskAssignedTo' => ['nullable', 'integer', 'exists:users,id'],
            'subtaskDueDate' => ['nullable', 'date'],
            'subtaskStatus' => ['required', Rule::enum(TaskStatus::class)],
        ]);

        if ($isEditing) {
            $subtask = Task::query()->findOrFail($this->editingSubtaskId);
            $this->authorize('update', $subtask);

            $data = [
                'project_id' => $this->task->project_id,
                'title' => $validated['subtaskTitle'],
                'description' => $validated['subtaskDescription'] ?: null,
                'assigned_to' => Auth::user()->can('assign', $subtask) && filled($validated['subtaskAssignedTo']) ? (int) $validated['subtaskAssignedTo'] : null,
                'due_date' => $validated['subtaskDueDate'] ?: null,
                'status' => TaskStatus::from($validated['subtaskStatus']),
            ];

            $subtask->update($data);
            $this->task->load(['project', 'assignee', 'comments.user', 'media', 'template', 'children.assignee', 'children.project']);
            $this->dispatch('toast', message: 'Subtask updated.', tone: 'success');
        } else {
            $this->authorize('create', Task::class);

            $data = [
                'project_id' => $this->task->project_id,
                'parent_task_id' => $this->task->id,
                'title' => $validated['subtaskTitle'],
                'description' => $validated['subtaskDescription'] ?: null,
                'type' => $this->task->type,
                'assigned_to' => Auth::user()->can('assign', $this->task) && filled($validated['subtaskAssignedTo']) ? (int) $validated['subtaskAssignedTo'] : null,
                'due_date' => $validated['subtaskDueDate'] ?: null,
                'status' => TaskStatus::from($validated['subtaskStatus']),
                'created_by' => Auth::id(),
                'time_spent_minutes' => 0,
            ];

            $subtask = Task::query()->create($data);
            $this->task->refresh()->load(['project', 'assignee', 'comments.user', 'media', 'template', 'children.assignee', 'children.project']);
            $this->dispatch('toast', message: 'Subtask created.', tone: 'success');
        }

        $this->showSubtaskForm = false;
        $this->resetSubtaskForm();
    }

    public function deleteSubtask(int $subtaskId): void
    {
        $subtask = Task::query()->findOrFail($subtaskId);
        $this->authorize('delete', $subtask);

        if ($subtask->children()->exists()) {
            $this->dispatch('toast', message: 'Cannot delete a subtask that still has subtasks.', tone: 'error');
            return;
        }

        foreach ($subtask->media as $media) {
            Storage::disk($media->disk)->delete($media->path);
            $media->delete();
        }

        $subtask->delete();
        $this->task->refresh()->load(['project', 'assignee', 'comments.user', 'media', 'template', 'children.assignee', 'children.project']);
        $this->dispatch('toast', message: 'Subtask deleted.', tone: 'success');
    }

    public function start(TaskWorkflowService $workflow): void
    {
        $this->authorize('update', $this->task);
        $this->runWorkflow(fn () => $workflow->start($this->task, Auth::user()));
    }

    public function submit(TaskWorkflowService $workflow): void
    {
        $this->authorize('submit', $this->task);
        $this->runWorkflow(fn () => $workflow->submit($this->task, Auth::user()));
    }

    public function approve(TaskWorkflowService $workflow): void
    {
        $this->authorize('approve', $this->task);
        $this->runWorkflow(fn () => $workflow->approve($this->task, Auth::user()));
    }

    public function openReject(): void
    {
        $this->authorize('approve', $this->task);
        $this->showReject = true;
        $this->rejection_reason = '';
    }

    public function reject(TaskWorkflowService $workflow): void
    {
        $this->authorize('approve', $this->task);

        try {
            $workflow->reject($this->task, Auth::user(), $this->rejection_reason);
            $this->task->refresh()->load(['project', 'assignee', 'comments.user', 'media', 'template']);
            $this->showReject = false;
            $this->dispatch('toast', message: 'Task rejected.', tone: 'success');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }
        }
    }

    public function addComment(): void
    {
        $this->authorize('view', $this->task);

        $validated = $this->validate([
            'commentBody' => ['required', 'string', 'max:5000'],
        ]);

        TaskComment::query()->create([
            'task_id' => $this->task->id,
            'user_id' => Auth::id(),
            'body' => $validated['commentBody'],
        ]);

        $this->commentBody = '';
        $this->task->load('comments.user');
        $this->dispatch('toast', message: 'Comment added.', tone: 'success');
    }

    public function uploadEvidence(): void
    {
        $this->authorize('update', $this->task);

        $this->validate([
            'evidence' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,gif,webp,txt,csv,doc,docx,xls,xlsx,zip'],
        ]);

        $path = $this->evidence->store('task-evidence/'.$this->task->id, 'local');

        Media::query()->create([
            'mediable_type' => Task::class,
            'mediable_id' => $this->task->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->evidence->getClientOriginalName(),
            'mime_type' => $this->evidence->getMimeType(),
            'size' => $this->evidence->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        $this->evidence = null;
        $this->task->load('media');
        $this->dispatch('toast', message: 'Evidence uploaded.', tone: 'success');
    }

    public function deleteEvidence(int $mediaId): void
    {
        $this->authorize('update', $this->task);
        $media = $this->task->media()->where('id', $mediaId)->firstOrFail();
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
        $this->task->load('media');
        $this->dispatch('toast', message: 'Evidence removed.', tone: 'success');
    }

    protected function runWorkflow(callable $fn): void
    {
        try {
            $fn();
            $this->task->refresh()->load(['project', 'assignee', 'comments.user', 'media', 'template']);
            $this->dispatch('toast', message: 'Task status updated.', tone: 'success');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }
        }
    }

    public function render()
    {
        return view('livewire.tasks.show', [
            'statusOptions' => TaskStatus::options(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'canApprove' => Auth::user()->can('approve', $this->task),
            'canSubmit' => Auth::user()->can('submit', $this->task),
            'canUpdate' => Auth::user()->can('update', $this->task),
            'canAssign' => Auth::user()->can('assign', $this->task),
            'canCreate' => Auth::user()->can('create', Task::class),
            'canDelete' => Auth::user()->can('delete', $this->task),
        ]);
    }
}
