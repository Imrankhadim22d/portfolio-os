@php
    $selectedCount = collect($selected)->filter()->count();
@endphp

<div class="space-y-6">
    <x-page-header
        title="Expenses"
        subtitle="Direct and shared costs. Shared rows are allocated across active sites by monthly revenue."
        :breadcrumbs="[['label' => 'Money'], ['label' => 'Expenses']]"
    >
        <x-slot:actions>
            <x-button variant="secondary" icon="download" wire:click="exportCsv">Export CSV</x-button>
            @if ($canManage)
                <x-button variant="secondary" icon="refresh" wire:click="$set('showRecurring', true)">Recurring</x-button>
                <x-button icon="plus" wire:click="create">Add expense</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar target="search,projectFilter,monthFilter,paidFilter">
        <x-input
            class="min-w-[12rem] flex-1 sm:max-w-xs"
            size="sm"
            icon="search"
            type="search"
            data-page-search
            wire:model.live.debounce.300ms="search"
            placeholder="Search description or notes…"
        />
        <x-select size="sm" class="w-auto" wire:model.live="projectFilter" placeholder="All projects">
            @foreach ($projects as $p)
                <option value="{{ $p->id }}">{{ $p->domain }}</option>
            @endforeach
        </x-select>
        <x-input size="sm" class="w-auto" type="month" wire:model.live="monthFilter" aria-label="Month" />
        <x-select size="sm" class="w-auto" wire:model.live="paidFilter" placeholder="Paid status">
            <option value="paid">Paid</option>
            <option value="unpaid">Unpaid</option>
        </x-select>
        <x-slot:trailing>{{ $expenses->total() }} rows</x-slot:trailing>
    </x-filter-bar>

    @if ($showForm && $canManage)
        <x-card
            :title="$editingId ? 'Edit expense' : 'New expense'"
            subtitle="Shared costs are split across active sites by that month's revenue."
        >
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="sm:col-span-2 lg:col-span-3">
                    <x-select label="Expense type" wire:model.live="expense_type" :error="$errors->first('expense_type')">
                        <option value="project">Project Expense</option>
                        <option value="personal">Personal / Office Expense</option>
                    </x-select>
                </div>

                @if ($expense_type === 'personal')
                    @if ($canManageAllPersonal)
                        <x-select label="Owner" wire:model="owner_user_id" placeholder="Select owner" :error="$errors->first('owner_user_id')">
                            @foreach ($users as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </x-select>
                    @endif

                    @if ($canManageAllPersonal)
                        <x-select label="Paid By *" wire:model="paid_by_user_id" placeholder="Select paid by" :error="$errors->first('paid_by_user_id')">
                            @foreach ($users as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </x-select>
                    @else
                        <x-input label="Paid By *" value="{{ Auth::user()->name }}" disabled :error="$errors->first('paid_by_user_id')" />
                    @endif

                    <div class="sm:col-span-2 lg:col-span-3">
                        <div class="font-medium text-sm text-ink">Divide Between *</div>
                        <div class="mt-2 flex flex-wrap gap-4">
                            @foreach ($users as $u)
                                <x-checkbox wire:model="divide_between.{{ $u->id }}" label="{{ $u->name }}" />
                            @endforeach
                        </div>
                        @error('divide_between')<span class="text-red-500 text-xs">{{ $message }}</span>@enderror
                    </div>
                @else
                    <div class="sm:col-span-2 lg:col-span-3">
                        <x-checkbox
                            wire:model.live="is_shared"
                            label="Shared cost"
                            hint="Split by monthly revenue across active sites instead of billing one project."
                        />
                    </div>

                    @unless ($is_shared)
                        <x-select
                            label="Project"
                            wire:model="project_id"
                            placeholder="Select…"
                            :error="$errors->first('project_id')"
                            required
                        >
                            @foreach ($projects as $p)
                                <option value="{{ $p->id }}">{{ $p->domain }}</option>
                            @endforeach
                        </x-select>
                    @endunless

                    <x-select
                        label="Category"
                        wire:model="expense_category_id"
                        placeholder="—"
                        :error="$errors->first('expense_category_id')"
                    >
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </x-select>
                @endif

                <x-input
                    label="Amount"
                    wire:model="amount"
                    :error="$errors->first('amount')"
                    :suffix="\App\Support\Currency::code()"
                    required
                />

                <x-input
                    label="{{ $expense_type === 'personal' ? 'Payment Reason *' : 'Description' }}"
                    wire:model="description"
                    :error="$errors->first('description')"
                    required
                />

                <x-input
                    type="date"
                    label="Date"
                    wire:model="expense_date"
                    :error="$errors->first('expense_date')"
                    required
                />

                <x-textarea
                    label="Notes"
                    wire:model="notes"
                    rows="2"
                    :error="$errors->first('notes')"
                />

                <x-file-input
                    label="Receipt"
                    wire:model="receipt"
                    :filename="$receipt?->getClientOriginalName()"
                    :error="$errors->first('receipt')"
                    hint="Optional — image or PDF, up to 5 MB"
                />

                <div class="sm:col-span-2 lg:col-span-3">
                    <x-checkbox wire:model="is_paid" label="Already paid" />
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <x-button wire:click="save">Save</x-button>
                <x-button variant="ghost" wire:click="$set('showForm', false)">Cancel</x-button>
            </div>
        </x-card>
    @endif

    @if ($showRecurring && $canManage)
        <x-card
            title="Recurring expense template"
            subtitle="Generated by the expenses:generate-recurring command on its due day."
        >
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input
                    label="Description"
                    wire:model="rec_description"
                    :error="$errors->first('rec_description')"
                    required
                />

                <x-input
                    label="Amount"
                    wire:model="rec_amount"
                    :error="$errors->first('rec_amount')"
                    :suffix="\App\Support\Currency::code()"
                    required
                />

                <x-input
                    type="number"
                    label="Day of month"
                    wire:model="rec_day"
                    :error="$errors->first('rec_day')"
                    hint="1–28, so every month can run it."
                    min="1"
                    max="28"
                    required
                />

                @unless ($rec_is_shared)
                    <x-select
                        label="Project"
                        wire:model="rec_project_id"
                        placeholder="Project…"
                        :error="$errors->first('rec_project_id')"
                        required
                    >
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}">{{ $p->domain }}</option>
                        @endforeach
                    </x-select>
                @endunless

                <x-select
                    label="Category"
                    wire:model="rec_category_id"
                    placeholder="Category…"
                    :error="$errors->first('rec_category_id')"
                >
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </x-select>

                <div class="flex items-end">
                    <x-checkbox
                        wire:model.live="rec_is_shared"
                        label="Shared"
                        hint="Allocate across sites instead of one project."
                    />
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <x-button wire:click="saveRecurring">Save template</x-button>
                <x-button variant="ghost" wire:click="$set('showRecurring', false)">Cancel</x-button>
            </div>

            @if ($recurring->isNotEmpty())
                <div class="mt-6">
                    <p class="mb-2 font-mono text-eyebrow text-muted uppercase">Active templates</p>
                    <x-table
                        :headers="[
                            'Description',
                            ['label' => 'Next run', 'align' => 'right'],
                            ['label' => \App\Support\Currency::code(), 'align' => 'right'],
                        ]"
                    >
                        @foreach ($recurring as $r)
                            <x-table.row>
                                <x-table.cell>{{ $r->description }}</x-table.cell>
                                <x-table.cell numeric muted>{{ $r->next_run_date->format('Y-m-d') }}</x-table.cell>
                                <x-table.cell numeric>
                                    <x-money :paisa="$r->amount_paisa" />
                                </x-table.cell>
                            </x-table.row>
                        @endforeach
                    </x-table>
                </div>
            @endif
        </x-card>
    @endif

    <div wire:loading.delay.long.flex wire:target="search,projectFilter,monthFilter,paidFilter" class="hidden">
        <x-skeleton variant="table" class="w-full" :rows="6" :cols="5" />
    </div>

    @if ($expenses->isEmpty())
        <x-empty-state
            icon="expenses"
            title="No expenses for this period"
            description="Expenses are either direct to one site or shared and allocated by revenue. Clear the month or paid filter, or record the first cost."
        >
            @if ($canManage)
                <x-button icon="plus" wire:click="create">Add expense</x-button>
                <x-button variant="secondary" icon="refresh" wire:click="$set('showRecurring', true)">Recurring</x-button>
            @endif
        </x-empty-state>
    @else
        <div wire:loading.class="opacity-60" wire:target="search,projectFilter,monthFilter,paidFilter" class="transition-opacity">
            <x-table
                :headers="array_merge(
                    $canManage ? [['label' => 'Select', 'sr' => true, 'width' => 'w-10']] : [],
                    [
                        ['label' => 'Date', 'width' => 'w-28'],
                        'Type',
                        'Project / Owner',
                        'Category',
                        'Description',
                        ['label' => \App\Support\Currency::code(), 'align' => 'right'],
                        'Paid',
                    ],
                    $canManage ? [['label' => 'Actions', 'align' => 'right', 'width' => 'w-24']] : []
                )"
            >
                @foreach ($expenses as $e)
                    <x-table.row :selected="(bool) ($selected[$e->id] ?? false)">
                        @if ($canManage)
                            <x-table.cell tight>
                                <x-checkbox
                                    wire:model.live="selected.{{ $e->id }}"
                                    aria-label="Select expense {{ $e->description }}"
                                />
                            </x-table.cell>
                        @endif

                        <x-table.cell mono nowrap>{{ $e->expense_date->format('Y-m-d') }}</x-table.cell>
                        <x-table.cell>
                            @if (($e->expense_type ?? 'project') === 'personal')
                                <x-badge tone="accent">Personal</x-badge>
                            @elseif ($e->is_shared)
                                <x-badge tone="warn">Shared</x-badge>
                            @else
                                <x-badge tone="neutral">Project</x-badge>
                            @endif
                        </x-table.cell>
                        <x-table.cell>
                            @if (($e->expense_type ?? 'project') === 'personal')
                                <span class="font-medium text-ink">{{ $e->owner?->name ?? 'Personal' }}</span>
                            @elseif ($e->is_shared)
                                <x-badge tone="warn">Shared</x-badge>
                            @else
                                <span class="font-medium text-ink">{{ $e->project?->domain }}</span>
                            @endif
                        </x-table.cell>
                        <x-table.cell muted>{{ $e->category?->name ?? '—' }}</x-table.cell>
                        <x-table.cell>
                            {{ $e->description }}
                            @if (filled($e->receipt_path))
                                <a
                                    href="{{ route('expenses.receipt', $e) }}"
                                    class="ml-1.5 inline-flex items-center gap-1 align-middle text-xs text-accent hover:underline"
                                    aria-label="Download receipt for {{ $e->description }}"
                                >
                                    <x-icon name="download" class="size-3" />
                                    <span>Receipt</span>
                                </a>
                            @endif
                        </x-table.cell>
                        <x-table.cell numeric>
                            <x-money :paisa="$e->amount_paisa" />
                        </x-table.cell>
                        <x-table.cell>
                            @if ($e->is_paid)
                                <x-badge tone="success" dot>Paid</x-badge>
                            @else
                                <x-badge dot>Unpaid</x-badge>
                            @endif
                        </x-table.cell>

                        @if ($canManage)
                            <x-table.cell align="right" tight nowrap>
                                <div class="inline-flex items-center justify-end gap-0.5">
                                    <x-tooltip text="Edit expense">
                                        <x-button
                                            size="sm"
                                            variant="ghost"
                                            square
                                            icon="pencil"
                                            aria-label="Edit expense"
                                            wire:click="edit({{ $e->id }})"
                                        />
                                    </x-tooltip>
                                    <x-tooltip text="Delete expense">
                                        <x-button
                                            size="sm"
                                            variant="danger-ghost"
                                            square
                                            icon="trash"
                                            aria-label="Delete expense"
                                            wire:click="delete({{ $e->id }})"
                                            wire:confirm="Soft-delete?"
                                        />
                                    </x-tooltip>
                                </div>
                            </x-table.cell>
                        @endif
                    </x-table.row>
                @endforeach
            </x-table>
        </div>

        {{ $expenses->links() }}
    @endif

    @if ($canManage)
        <x-bulk-bar :count="$selectedCount">
            <x-button size="sm" variant="secondary" icon="check" wire:click="bulkMarkPaid">Mark paid</x-button>
        </x-bulk-bar>
    @endif
</div>
