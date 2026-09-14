<?php

namespace App\Livewire\Money;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Policies\FinancePolicy;
use App\Services\CsvExportService;
use App\Services\ExpenseService;
use App\Support\Currency;
use App\Support\DisplayTimezone;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Expenses')]
class ExpensesIndex extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $projectFilter = '';

    #[Url]
    public string $monthFilter = '';

    #[Url]
    public string $paidFilter = '';

    public bool $showForm = false;

    public bool $showRecurring = false;

    public ?int $editingId = null;

    public string $project_id = '';

    public string $expense_type = Expense::TYPE_PROJECT;

    public string $owner_user_id = '';

    public bool $is_shared = false;

    public string $expense_category_id = '';

    public string $amount = '';

    public string $description = '';

    public string $paid_by_user_id = '';

    public array $divide_between = [];

    public string $expense_date = '';

    public string $notes = '';

    public bool $is_paid = false;

    public $receipt = null;

    /** Recurring form */
    public string $rec_description = '';

    public string $rec_amount = '';

    public string $rec_day = '1';

    public string $rec_project_id = '';

    public bool $rec_is_shared = false;

    public string $rec_category_id = '';

    /** @var array<int, bool> */
    public array $selected = [];

    public function mount(): void
    {
        abort_unless(FinancePolicy::viewExpenses(Auth::user()), 403);
        app(ExpenseService::class)->seedSystemCategories();
        if ($this->monthFilter === '') {
            $this->monthFilter = DisplayTimezone::now()->format('Y-m');
        }
        $this->expense_date = DisplayTimezone::today();

        if (request()->boolean('new') && FinancePolicy::manageExpenses(Auth::user())) {
            $this->create();
        }
    }

    public function create(): void
    {
        Gate::authorize('create', Expense::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $e = Expense::query()->findOrFail($id);
        Gate::authorize('update', $e);
        $this->editingId = $e->id;
        $this->expense_type = $e->expense_type ?? Expense::TYPE_PROJECT;
        $this->project_id = (string) ($e->project_id ?? '');
        $this->owner_user_id = (string) ($e->owner_user_id ?? '');
        $this->paid_by_user_id = (string) ($e->paid_by_user_id ?? Auth::id());
        $this->divide_between = $e->participants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->is_shared = (bool) $e->is_shared;
        $this->expense_category_id = (string) ($e->expense_category_id ?? '');
        $this->amount = Money::fromMinor((int) $e->amount_paisa);
        $this->description = $e->description;
        $this->expense_date = $e->expense_date->format('Y-m-d');
        $this->notes = (string) $e->notes;
        $this->is_paid = (bool) $e->is_paid;
        $this->showForm = true;
    }

    public function save(ExpenseService $expenses): void
    {
        if ($this->editingId) {
            $expense = Expense::query()->findOrFail($this->editingId);
            Gate::authorize('update', $expense);
        } else {
            Gate::authorize('create', Expense::class);
        }

        $this->validate([
            'is_shared' => ['boolean'],
            'expense_type' => ['required', 'in:'.Expense::TYPE_PROJECT.','.Expense::TYPE_PERSONAL],
            'project_id' => [$this->expense_type === Expense::TYPE_PROJECT && ! $this->is_shared ? 'required' : 'nullable', 'integer', 'exists:projects,id'],
            'owner_user_id' => [$this->expense_type === Expense::TYPE_PERSONAL ? 'nullable' : 'sometimes', 'integer', 'exists:users,id'],
            'paid_by_user_id' => [$this->expense_type === Expense::TYPE_PERSONAL ? 'required' : 'nullable', 'integer', 'exists:users,id'],
            'divide_between' => [$this->expense_type === Expense::TYPE_PERSONAL ? 'required' : 'nullable', 'array', 'min:1'],
            'divide_between.*' => ['integer', 'exists:users,id'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => [$this->expense_type === Expense::TYPE_PERSONAL ? 'required' : 'required', 'string', 'max:500'],
            'expense_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_paid' => ['boolean'],
            'receipt' => ['nullable', 'file', 'max:5120', 'mimes:pdf,png,jpg,jpeg,gif,webp'],
        ]);

        $personalOwner = $this->expense_type === Expense::TYPE_PERSONAL
            ? ($this->owner_user_id !== '' ? (int) $this->owner_user_id : Auth::id())
            : null;

        if ($this->expense_type === Expense::TYPE_PERSONAL && ! Auth::user()->isAdmin()) {
            $personalOwner = Auth::id();
        }

        $paidById = $this->expense_type === Expense::TYPE_PERSONAL
            ? ($this->paid_by_user_id !== '' ? (int) $this->paid_by_user_id : Auth::id())
            : null;

        if ($this->expense_type === Expense::TYPE_PERSONAL && ! Auth::user()->isAdmin()) {
            $paidById = Auth::id();
        }

        $participants = $this->expense_type === Expense::TYPE_PERSONAL ? array_values(array_unique(array_map('intval', $this->divide_between))) : [];

        $data = [
            'project_id' => $this->project_id !== '' ? (int) $this->project_id : null,
            'expense_type' => $this->expense_type,
            'owner_user_id' => $personalOwner,
            'paid_by_user_id' => $paidById,
            'is_shared' => $this->is_shared,
            'expense_category_id' => $this->expense_category_id !== '' ? (int) $this->expense_category_id : null,
            'amount' => $this->amount,
            'description' => $this->description,
            'expense_date' => $this->expense_date,
            'notes' => $this->notes ?: null,
            'is_paid' => $this->is_paid,
            'participants' => $participants,
        ];

        if ($this->editingId) {
            $expenses->updateManual(Expense::query()->findOrFail($this->editingId), $data, Auth::user(), $this->receipt);
            $this->dispatch('toast', message: 'Expense updated.', tone: 'success');
        } else {
            $expenses->createManual($data, Auth::user(), $this->receipt);
            $this->dispatch('toast', message: 'Expense recorded.', tone: 'success');
        }

        $this->showForm = false;
        $this->resetForm();
        $this->receipt = null;
    }

    public function delete(int $id, ExpenseService $expenses): void
    {
        $expense = Expense::query()->findOrFail($id);
        Gate::authorize('delete', $expense);
        $expenses->softDelete($expense, Auth::user());
        $this->dispatch('toast', message: 'Expense soft-deleted.', tone: 'success');
    }

    public function bulkMarkPaid(ExpenseService $expenses): void
    {
        abort_unless(FinancePolicy::manageExpenses(Auth::user()), 403);
        $ids = collect($this->selected)->filter()->keys()->map(fn ($k) => (int) $k)->all();
        $count = $expenses->bulkMarkPaid($ids, Auth::user(), true);
        $this->selected = [];
        $this->dispatch('toast', message: "Marked {$count} expense(s) paid.", tone: 'success');
    }

    public function saveRecurring(ExpenseService $expenses): void
    {
        abort_unless(FinancePolicy::manageExpenses(Auth::user()), 403);
        $this->validate([
            'rec_description' => ['required', 'string', 'max:500'],
            'rec_amount' => ['required', 'numeric', 'min:0'],
            'rec_day' => ['required', 'integer', 'min:1', 'max:28'],
            'rec_is_shared' => ['boolean'],
            'rec_project_id' => [$this->rec_is_shared ? 'nullable' : 'required', 'integer', 'exists:projects,id'],
            'rec_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
        ]);

        $day = (int) $this->rec_day;
        $today = DisplayTimezone::now();
        $next = $today->copy()->startOfMonth()->day(min($day, $today->daysInMonth));
        if ($next->lt($today->copy()->startOfDay())) {
            $next = $next->addMonthNoOverflow()->day(min($day, $next->daysInMonth));
        }

        RecurringExpense::query()->create([
            'project_id' => $this->rec_is_shared ? null : (int) $this->rec_project_id,
            'is_shared' => $this->rec_is_shared,
            'expense_category_id' => $this->rec_category_id !== '' ? (int) $this->rec_category_id : null,
            'amount_paisa' => Money::toMinor($this->rec_amount),
            'description' => $this->rec_description,
            'day_of_month' => $day,
            'next_run_date' => $next->toDateString(),
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        $this->showRecurring = false;
        $this->dispatch('toast', message: 'Recurring expense template saved. Runs via expenses:generate-recurring.', tone: 'success');
    }

    public function exportCsv(CsvExportService $csv)
    {
        abort_unless(FinancePolicy::export(Auth::user()), 403);
        $rows = $this->baseQuery()->with(['project', 'category'])->orderByDesc('expense_date')->get()
            ->map(fn (Expense $e) => [
                $e->id,
                $e->is_shared ? 'shared' : ($e->project?->domain ?? ''),
                $e->category?->name,
                Money::fromMinor((int) $e->amount_paisa),
                $e->description,
                $e->expense_date->format('Y-m-d'),
                $e->is_paid ? 'paid' : 'unpaid',
            ]);

        return $csv->download('expenses.csv', [
            'id', 'project_or_shared', 'category', 'amount_'.strtolower(Currency::code()), 'description', 'date', 'status',
        ], $rows);
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->expense_type = Expense::TYPE_PROJECT;
        $this->project_id = $this->projectFilter;
        $this->owner_user_id = '';
        $this->paid_by_user_id = (string) Auth::id();
        $this->divide_between = [];
        $this->is_shared = false;
        $this->expense_category_id = '';
        $this->amount = '';
        $this->description = '';
        $this->expense_date = DisplayTimezone::today();
        $this->notes = '';
        $this->is_paid = false;
    }

    protected function baseQuery()
    {
        $user = Auth::user();

        return Expense::query()
            ->accessibleBy($user)
            ->when($this->projectFilter !== '', fn ($q) => $q->where(function ($q) {
                $q->where('project_id', $this->projectFilter)
                    ->orWhere('is_shared', true)
                    ->orWhere(function ($q2) {
                        $q2->where('expense_type', Expense::TYPE_PERSONAL)
                            ->where('owner_user_id', Auth::id());
                    });
            }))
            ->when($this->monthFilter !== '', function ($q) {
                $start = $this->monthFilter.'-01';
                $end = Carbon::parse($start)->endOfMonth()->toDateString();
                $q->whereBetween('expense_date', [$start, $end]);
            })
            ->when($this->paidFilter === 'paid', fn ($q) => $q->where('is_paid', true))
            ->when($this->paidFilter === 'unpaid', fn ($q) => $q->where('is_paid', false))
            ->when($this->search !== '', function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($inner) use ($s) {
                    $inner->where('description', 'like', $s)
                        ->orWhere('notes', 'like', $s);
                });
            });
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.money.expenses-index', [
            'expenses' => $this->baseQuery()->with(['project', 'category', 'owner'])->orderByDesc('expense_date')->orderByDesc('id')->paginate(20),
            'projects' => Project::query()->accessibleBy($user)->orderBy('domain')->get(),
            'categories' => ExpenseCategory::query()->orderBy('name')->get(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'canManage' => FinancePolicy::manageExpenses($user),
            'canManageAllPersonal' => $user->isAdmin(),
            'recurring' => RecurringExpense::query()->where('is_active', true)->orderBy('next_run_date')->limit(10)->get(),
        ]);
    }
}
