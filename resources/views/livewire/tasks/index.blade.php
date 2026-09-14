<div class="space-y-6">
    <x-page-header
        title="Tasks"
        subtitle="Setup checklists, recurring work, and ad-hoc assignments."
        :breadcrumbs="[['label' => 'Work'], ['label' => 'Tasks']]"
    >
        @if ($canCreate)
            <x-slot:actions>
                <x-button icon="plus" wire:click="create">New task</x-button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-filter-bar target="search,projectFilter,statusFilter,mineOnly">
        <x-input
            class="min-w-[12rem] flex-1 sm:max-w-xs"
            size="sm"
            icon="search"
            type="search"
            data-page-search
            wire:model.live.debounce.300ms="search"
            placeholder="Search tasks…"
            aria-label="Search tasks"
        />
        <x-select size="sm" class="w-auto" wire:model.live="projectFilter" placeholder="All projects" aria-label="Filter by project">
            @foreach ($projects as $project)
                <option value="{{ $project->id }}">{{ $project->domain }}</option>
            @endforeach
        </x-select>
        <x-select size="sm" class="w-auto" wire:model.live="statusFilter" placeholder="All statuses" aria-label="Filter by status">
            @foreach ($statusOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-select>
        <x-checkbox class="px-1" label="Mine only" wire:model.live="mineOnly" value="1" />

        <x-slot:trailing>{{ $tasks->total() }} tasks</x-slot:trailing>
    </x-filter-bar>

    <div wire:loading.delay.long.flex wire:target="search,projectFilter,statusFilter,mineOnly" class="hidden">
        <x-skeleton variant="table" class="w-full" :rows="6" :cols="5" />
    </div>

    @if ($tasks->isEmpty())
        <x-empty-state
            icon="tasks"
            title="No tasks match this view"
            description="Create a project to auto-generate its setup SEO checklist, or add ad-hoc work for the team."
        >
            @if ($canCreate)
                <x-button icon="plus" wire:click="create">New task</x-button>
            @endif
        </x-empty-state>
    @else
        @php
            $selectable = $canAssign || $canApprove;
            $headers = array_values(array_filter([
                $selectable ? ['label' => 'Select', 'sr' => true, 'width' => 'relative w-10'] : null,
                'Task',
                'Project',
                'Assignee',
                ['label' => 'Due', 'align' => 'right'],
                'Status',
                ['label' => 'Actions', 'sr' => true, 'align' => 'right', 'width' => 'relative'],
            ]));
        @endphp

        <div wire:loading.class="opacity-60" wire:target="search,projectFilter,statusFilter,mineOnly">
            <x-table :headers="$headers">
                @foreach ($tasks as $task)
                    <x-table.row wire:key="task-{{ $task->id }}" :selected="in_array($task->id, $selectedIds)">
                        @if ($selectable)
                            <x-table.cell tight>
                                <x-checkbox
                                    wire:model.live="selectedIds"
                                    value="{{ $task->id }}"
                                    aria-label="Select {{ $task->title }}"
                                />
                            </x-table.cell>
                        @endif
                        <x-table.cell class="min-w-[12rem]">
                            <div class="{{ $task->parent_task_id ? 'pl-4 border-l border-line' : '' }}">
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium text-ink transition-colors hover:text-accent">
                                    {{ $task->title }}
                                </a>
                                <p class="mt-0.5 font-mono text-[10px] tracking-[0.08em] text-faint uppercase">{{ $task->type->label() }}</p>
                            </div>
                        </x-table.cell>
                        <x-table.cell muted nowrap>{{ $task->project?->domain }}</x-table.cell>
                        <x-table.cell>
                            @if ($task->assignee)
                                <span class="inline-flex items-center gap-2">
                                    <x-avatar :name="$task->assignee->name" size="xs" />
                                    {{ $task->assignee->name }}
                                </span>
                            @else
                                <span class="text-faint">—</span>
                            @endif
                        </x-table.cell>
                        <x-table.cell numeric muted nowrap>
                            {{ $task->due_date?->timezone(\App\Support\DisplayTimezone::name())->format('Y-m-d') ?? '—' }}
                        </x-table.cell>
                        <x-table.cell>
                            <x-badge :tone="match($task->status->value) {
                                'approved' => 'success',
                                'rejected' => 'danger',
                                'submitted' => 'warn',
                                default => 'accent',
                            }">{{ $task->status->label() }}</x-badge>
                        </x-table.cell>
                        <x-table.cell align="right" nowrap>
                            <div class="flex items-center justify-end gap-1">
                                @can('update', $task)
                                    <x-tooltip text="Edit task">
                                        <x-button size="sm" variant="ghost" square icon="pencil" wire:click="edit({{ $task->id }})" aria-label="Edit {{ $task->title }}" />
                                    </x-tooltip>
                                @endcan
                                <x-tooltip text="Open task">
                                    <x-button
                                        size="sm"
                                        variant="ghost"
                                        square
                                        icon="arrow-right"
                                        :href="route('tasks.show', $task)"
                                        wire:navigate
                                        aria-label="Open {{ $task->title }}"
                                    />
                                </x-tooltip>
                            </div>
                        </x-table.cell>
                    </x-table.row>
                @endforeach
            </x-table>
        </div>

        <div>{{ $tasks->links() }}</div>

        @if ($canAssign || $canApprove)
            <x-bulk-bar :count="count($selectedIds)" clear="clearSelection">
                @if ($canAssign)
                    <x-select size="sm" class="w-auto" wire:model="bulkAssignTo" placeholder="Assign to…" aria-label="Assign selected tasks to">
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </x-select>
                    <x-button size="sm" variant="secondary" wire:click="applyBulkAssign">Assign</x-button>
                @endif
                @if ($canApprove || $canAssign)
                    <x-select size="sm" class="w-auto" wire:model="bulkStatus" placeholder="Status…" aria-label="Set status of selected tasks">
                        @foreach ($statusOptions as $value => $label)
                            @if (! in_array($value, ['rejected', 'approved'], true) || $canApprove)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endif
                        @endforeach
                    </x-select>
                    <x-button size="sm" variant="secondary" wire:click="applyBulkStatus" wire:confirm="Apply this status to the selected tasks?">Apply</x-button>
                @endif
            </x-bulk-bar>
        @endif
    @endif

    <x-modal
        :show="$showForm"
        :title="$editingId ? 'Edit task' : 'New task'"
        subtitle="Recurring work generates its first instance immediately."
        close="cancel"
        size="lg"
    >
        <form id="task-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-select
                label="Project"
                wire:model="project_id"
                placeholder="Select…"
                :error="$errors->first('project_id')"
                class="sm:col-span-2"
                required
            >
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}">{{ $project->domain }}</option>
                @endforeach
            </x-select>

            <x-input label="Title" wire:model="title" :error="$errors->first('title')" class="sm:col-span-2" required />

            <x-textarea label="Description" wire:model="description" rows="3" :error="$errors->first('description')" class="sm:col-span-2" />

            <x-select label="Type" wire:model="type" :error="$errors->first('type')">
                @foreach ($typeOptions as $value => $label)
                    @if ($value !== 'setup')
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endif
                @endforeach
            </x-select>

            <x-select label="Recurrence" wire:model="recurrence_frequency" placeholder="None" :error="$errors->first('recurrence_frequency')">
                @foreach ($frequencyOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select>

            <x-select label="Assignee" wire:model="assigned_to" placeholder="Unassigned" :error="$errors->first('assigned_to')">
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </x-select>

            <x-input label="Due date" type="date" wire:model="due_date" :error="$errors->first('due_date')" />

            <x-input
                label="Time spent"
                type="number"
                min="0"
                wire:model="time_spent_minutes"
                :error="$errors->first('time_spent_minutes')"
                suffix="min"
            />
        </form>

        <x-slot:footer>
            <x-button variant="ghost" wire:click="cancel">Cancel</x-button>
            <x-button type="submit" form="task-form" target="save">Save task</x-button>
        </x-slot:footer>
    </x-modal>
</div>
