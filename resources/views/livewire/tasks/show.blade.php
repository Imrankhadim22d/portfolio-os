<div class="space-y-6">
    <x-page-header
        :title="$task->title"
        :breadcrumbs="[
            ['label' => 'Work'],
            ['label' => 'Tasks', 'href' => route('tasks.index')],
            ['label' => $task->title],
        ]"
        back="{{ route('tasks.index') }}"
    >
        <x-slot:meta>
            <x-badge :tone="match($task->status->value) {
                'approved' => 'success',
                'rejected' => 'danger',
                'submitted' => 'warn',
                default => 'accent',
            }" dot>{{ $task->status->label() }}</x-badge>
            <x-badge tone="neutral">{{ $task->type->label() }}</x-badge>
            @if ($task->project)
                <span class="font-mono text-xs text-muted">{{ $task->project->domain }}</span>
            @endif
        </x-slot:meta>
    </x-page-header>

    @if ($task->rejection_reason)
        <div class="rounded-xl border border-danger-line bg-danger-soft px-4 py-3">
            <p class="flex items-center gap-1.5 text-sm font-semibold text-danger">
                <x-icon name="alert" class="size-4" />
                Rejected
            </p>
            <p class="mt-1 text-sm text-danger">{{ $task->rejection_reason }}</p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-6">
            @if ($task->description)
                <x-card title="Description">
                    <p class="text-sm whitespace-pre-wrap text-ink-soft">{{ $task->description }}</p>
                </x-card>
            @endif

            <x-card title="Subtasks" icon="sitemap">
                @if ($canCreate)
                    <div class="flex justify-end">
                        <x-button size="sm" icon="plus" wire:click="openSubtaskForm">Add subtask</x-button>
                    </div>
                @endif

                @if ($showSubtaskForm)
                    <form wire:submit="saveSubtask" class="mt-4 grid gap-3 rounded-xl border border-line bg-subtle/50 p-4">
                        <x-input label="Title" wire:model="subtaskTitle" :error="$errors->first('subtaskTitle')" required />
                        <x-textarea label="Description" wire:model="subtaskDescription" rows="3" :error="$errors->first('subtaskDescription')" />
                        @if ($canAssign)
                            <x-select label="Assignee" wire:model="subtaskAssignedTo" placeholder="Unassigned" :error="$errors->first('subtaskAssignedTo')">
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </x-select>
                        @endif
                        <x-input label="Due date" type="date" wire:model="subtaskDueDate" :error="$errors->first('subtaskDueDate')" />
                        <x-select label="Status" wire:model="subtaskStatus" :error="$errors->first('subtaskStatus')">
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-select>
                        <div class="flex justify-end gap-2">
                            <x-button size="sm" variant="ghost" wire:click="cancelSubtaskForm">Cancel</x-button>
                            <x-button size="sm" type="submit" target="saveSubtask">{{ $editingSubtaskId ? 'Update subtask' : 'Create subtask' }}</x-button>
                        </div>
                    </form>
                @endif

                @if ($task->children->isNotEmpty())
                    <ul class="mt-4 space-y-3">
                        @foreach ($task->children as $child)
                            <li class="rounded-xl border border-line bg-subtle/50 px-3 py-3" wire:key="subtask-{{ $child->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <a href="{{ route('tasks.show', $child) }}" wire:navigate class="text-sm font-semibold text-ink hover:text-accent hover:underline">
                                            {{ $child->title }}
                                        </a>
                                        @if ($child->description)
                                            <p class="mt-1 text-xs text-muted line-clamp-2">{{ $child->description }}</p>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-1">
                                        @can('update', $child)
                                            <x-tooltip text="Edit subtask">
                                                <x-button size="sm" square variant="ghost" icon="pencil" wire:click="editSubtask({{ $child->id }})" aria-label="Edit {{ $child->title }}" />
                                            </x-tooltip>
                                        @endcan
                                        @can('delete', $child)
                                            <x-tooltip text="Delete subtask">
                                                <x-button size="sm" square variant="danger-ghost" icon="trash" wire:click="deleteSubtask({{ $child->id }})" wire:confirm="Delete this subtask?" aria-label="Delete {{ $child->title }}" />
                                            </x-tooltip>
                                        @endcan
                                        <span class="inline-flex items-center gap-1 px-2 py-1 font-mono text-[10px] uppercase text-faint">
                                            {{ $child->status->label() }}
                                        </span>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 text-sm text-muted">No subtasks yet.</p>
                @endif
            </x-card>

            @if ($canUpdate)
                <x-card title="Evidence" subtitle="Attach screenshots, docs, or other proof for this task." icon="upload">
                    <form wire:submit="uploadEvidence" class="space-y-3">
                        <x-file-input
                            wire:model="evidence"
                            :filename="$evidence?->getClientOriginalName()"
                            :error="$errors->first('evidence')"
                            hint="Max 10 MB"
                        />
                        <div class="flex justify-end">
                            <x-button type="submit" size="sm" icon="upload" target="uploadEvidence">Upload</x-button>
                        </div>
                    </form>

                    @if ($task->media->isNotEmpty())
                        <ul class="mt-4 divide-y divide-line border-t border-line">
                            @foreach ($task->media as $file)
                                <li class="flex items-center justify-between gap-3 py-2.5" wire:key="media-{{ $file->id }}">
                                    <a href="{{ route('media.download', $file) }}" class="min-w-0 flex-1 truncate text-sm font-medium text-ink hover:text-accent hover:underline">{{ $file->original_name }}</a>
                                    <span class="font-mono text-[11px] text-faint tabular-nums">{{ number_format($file->size / 1024, 0) }} KB</span>
                                    <x-tooltip text="Download">
                                        <x-button size="sm" square variant="ghost" icon="download" href="{{ route('media.download', $file) }}" aria-label="Download {{ $file->original_name }}" />
                                    </x-tooltip>
                                    <x-tooltip text="Remove file">
                                        <x-button
                                            size="sm"
                                            variant="danger-ghost"
                                            square
                                            icon="trash"
                                            wire:click="deleteEvidence({{ $file->id }})"
                                            wire:confirm="Remove this evidence file?"
                                            aria-label="Remove {{ $file->original_name }}"
                                        />
                                    </x-tooltip>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-4 border-t border-line pt-4 text-sm text-muted">No evidence attached yet.</p>
                    @endif
                </x-card>
            @elseif ($task->media->isNotEmpty())
                <x-card title="Evidence" icon="upload">
                    <ul class="divide-y divide-line">
                        @foreach ($task->media as $file)
                            <li class="py-2">
                                <a href="{{ route('media.download', $file) }}" class="block truncate text-sm font-medium text-ink hover:text-accent hover:underline">{{ $file->original_name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif

            <x-card title="Comments" icon="inbox">
                @if ($task->comments->isNotEmpty())
                    <ul class="space-y-3">
                        @foreach ($task->comments as $comment)
                            <li class="rounded-lg border border-line bg-subtle/50 px-3 py-2.5" wire:key="comment-{{ $comment->id }}">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                                    {{ $comment->user?->name }}
                                    <span class="font-mono text-[11px] font-normal text-faint tabular-nums">
                                        {{ $comment->created_at?->timezone(\App\Support\DisplayTimezone::name())->format('Y-m-d H:i') }}
                                    </span>
                                </p>
                                <p class="mt-1 text-sm whitespace-pre-wrap text-ink-soft">{{ $comment->body }}</p>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-muted">No comments yet — leave the first note for whoever picks this up.</p>
                @endif

                <form wire:submit="addComment" class="mt-4 space-y-2 border-t border-line pt-4">
                    <x-textarea
                        wire:model="commentBody"
                        rows="2"
                        placeholder="Add a comment…"
                        :error="$errors->first('commentBody')"
                        aria-label="Add a comment"
                    />
                    <div class="flex justify-end">
                        <x-button type="submit" size="sm" target="addComment">Comment</x-button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            @php
                $canStart = $canUpdate && in_array($task->status->value, ['assigned', 'rejected'], true);
                $canSubmitNow = $canSubmit && in_array($task->status->value, ['assigned', 'in_progress', 'rejected'], true);
                $canDecide = $canApprove && $task->status->value === 'submitted';
            @endphp

            @if ($canStart || $canSubmitNow || $canDecide)
                <x-card title="Workflow" subtitle="Status moves are logged against this task." icon="approvals">
                    <div class="flex flex-wrap gap-2">
                        @if ($canStart)
                            <x-button size="sm" variant="secondary" wire:click="start">Start</x-button>
                        @endif
                        @if ($canSubmitNow)
                            <x-button size="sm" wire:click="submit">Submit for approval</x-button>
                        @endif
                        @if ($canDecide)
                            <x-button size="sm" icon="check" wire:click="approve">Approve</x-button>
                            <x-button size="sm" variant="danger-soft" wire:click="openReject">Reject</x-button>
                        @endif
                    </div>

                    @if ($showReject)
                        <div class="mt-4 space-y-3 border-t border-line pt-4">
                            <x-textarea
                                label="Rejection reason"
                                wire:model="rejection_reason"
                                rows="3"
                                placeholder="Reason required…"
                                :error="$errors->first('rejection_reason')"
                                required
                            />
                            <div class="flex flex-wrap gap-2">
                                <x-button size="sm" variant="danger" wire:click="reject">Confirm reject</x-button>
                                <x-button size="sm" variant="ghost" wire:click="$set('showReject', false)">Cancel</x-button>
                            </div>
                        </div>
                    @endif
                </x-card>
            @endif

            @if ($canUpdate)
                <x-card title="Details" subtitle="Edit inline, then save." icon="pencil">
                    <form wire:submit="saveMeta" class="space-y-4">
                        <x-input
                            label="Time spent"
                            type="number"
                            min="0"
                            wire:model="time_spent_minutes"
                            :error="$errors->first('time_spent_minutes')"
                            suffix="min"
                        />
                        <x-input label="Due date" type="date" wire:model="due_date" :error="$errors->first('due_date')" />
                        @if ($canAssign)
                            <x-select label="Assignee" wire:model="assigned_to" placeholder="Unassigned" :error="$errors->first('assigned_to')">
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </x-select>
                        @endif
                        <x-button type="submit" size="sm" variant="secondary" target="saveMeta">Save details</x-button>
                    </form>
                </x-card>
            @else
                <x-card title="Details" icon="tasks">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted">Assignee</dt>
                            <dd class="font-medium text-ink">{{ $task->assignee?->name ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted">Due</dt>
                            <dd class="font-mono text-xs text-ink tabular-nums">{{ $task->due_date?->format('Y-m-d') ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted">Time spent</dt>
                            <dd class="font-mono text-xs text-ink tabular-nums">{{ $task->time_spent_minutes }} min</dd>
                        </div>
                    </dl>
                </x-card>
            @endif

            @if ($canDelete)
                <x-card title="Delete" icon="trash">
                    <div class="flex justify-end">
                        <x-button size="sm" variant="danger-ghost" wire:click="deleteTask" wire:confirm="Delete this task and any direct evidence or comments? This will not delete subtasks unless they are also removed safely." aria-label="Delete {{ $task->title }}">
                            Delete task
                        </x-button>
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>
