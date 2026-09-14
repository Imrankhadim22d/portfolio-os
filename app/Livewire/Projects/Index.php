<?php

namespace App\Livewire\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\ProfitAndLossService;
use App\Services\ProjectOwnershipService;

use App\Support\Money;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Projects')]
class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showForm = false;

    public ?int $editingProjectId = null;

    public string $domain = '';

    public string $niche = '';

    public string $cms = '';

    public string $start_date = '';

    /** Base-currency major units string for form input, stored as minor units. */
    public string $acquisition_cost = '0';

    public string $status = 'setup';

    public string $notes = '';

    /** @var array<int, array{user_id: string, share_percent: string}> */
    public array $owners = [];

    /** @var array<int, int> */
    public array $selectedIds = [];

    public string $bulkStatus = '';

    public function mount(): void
    {
        // Quick-create deep link from the command palette / "New" menu.
        if (request()->boolean('new') && Auth::user()?->hasPermission('projects.create')) {
            $this->create();
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('create', Project::class);
        $this->resetForm();
        $this->owners = [
            ['user_id' => (string) Auth::id(), 'share_percent' => '100'],
        ];
        $this->showForm = true;
    }

    public function edit(int $projectId): void
    {
        $project = Project::query()->with('owners')->findOrFail($projectId);
        $this->authorize('update', $project);

        $this->editingProjectId = $project->id;
        $this->domain = $project->domain;
        $this->niche = (string) $project->niche;
        $this->cms = (string) $project->cms;
        $this->start_date = $project->start_date?->format('Y-m-d') ?? '';
        $this->acquisition_cost = Money::fromMinor((int) $project->acquisition_cost_paisa);
        $this->status = $project->status->value;
        $this->notes = (string) $project->notes;
        $this->owners = $project->owners->map(fn (User $u) => [
            'user_id' => (string) $u->id,
            'share_percent' => number_format($u->pivot->share_bps / 100, 2, '.', ''),
        ])->values()->all();

        if ($this->owners === []) {
            $this->owners = [['user_id' => (string) Auth::id(), 'share_percent' => '100']];
        }

        $this->showForm = true;
    }

    public function addOwnerRow(): void
    {
        $this->owners[] = ['user_id' => '', 'share_percent' => ''];
    }

    public function removeOwnerRow(int $index): void
    {
        unset($this->owners[$index]);
        $this->owners = array_values($this->owners);
    }

    public function save(ProjectOwnershipService $ownership): void
    {
        $isCreating = $this->editingProjectId === null;

        if ($isCreating) {
            $this->authorize('create', Project::class);
        } else {
            $project = Project::query()->findOrFail($this->editingProjectId);
            $this->authorize('update', $project);
        }

        $validated = $this->validate([
            'domain' => ['required', 'string', 'max:255'],
            'niche' => ['nullable', 'string', 'max:255'],
            'cms' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'acquisition_cost' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'owners' => ['required', 'array', 'min:1'],
            'owners.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'owners.*.share_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        $paisa = Money::toMinor($validated['acquisition_cost']);

        $ownerRows = collect($validated['owners'])
            ->map(fn (array $row) => [
                'user_id' => (int) $row['user_id'],
                'share_bps' => (int) round(((float) $row['share_percent']) * 100),
            ])
            ->all();

        // Normalize rounding remainder onto first owner so 33.33*3 works better
        $sumBps = array_sum(array_column($ownerRows, 'share_bps'));
        if ($sumBps !== ProjectOwnershipService::TOTAL_BPS && count($ownerRows) === 1) {
            $ownerRows[0]['share_bps'] = ProjectOwnershipService::TOTAL_BPS;
        }

        try {
            DB::transaction(function () use ($isCreating, $validated, $paisa, $ownerRows, $ownership) {
                if ($isCreating) {
                    $project = Project::query()->create([
                        'domain' => $validated['domain'],
                        'niche' => $validated['niche'] ?: null,
                        'cms' => $validated['cms'] ?: null,
                        'start_date' => $validated['start_date'] ?: null,
                        'acquisition_cost_paisa' => $paisa,
                        'status' => $validated['status'],
                        'notes' => $validated['notes'] ?: null,
                    ]);

                    $ownership->sync($project, $ownerRows);

                } else {
                    $project = Project::query()->findOrFail($this->editingProjectId);
                    $project->update([
                        'domain' => $validated['domain'],
                        'niche' => $validated['niche'] ?: null,
                        'cms' => $validated['cms'] ?: null,
                        'start_date' => $validated['start_date'] ?: null,
                        'acquisition_cost_paisa' => $paisa,
                        'status' => $validated['status'],
                        'notes' => $validated['notes'] ?: null,
                    ]);

                    if (Auth::user()->can('manageOwnership', $project)) {
                        $ownership->sync($project, $ownerRows);
                    }
                }
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('toast', message: $isCreating ? 'Project created.' : 'Project updated.', tone: 'success');
    }

    public function delete(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);
        $this->authorize('delete', $project);
        $project->delete();
        $this->dispatch('toast', message: 'Project archived.', tone: 'success');
    }

    public function applyBulkStatus(): void
    {
        abort_unless(Auth::user()?->hasPermission('projects.update'), 403);

        $this->validate([
            'bulkStatus' => ['required', Rule::enum(ProjectStatus::class)],
            'selectedIds' => ['required', 'array', 'min:1'],
            'selectedIds.*' => ['integer', 'exists:projects,id'],
        ]);

        $projects = Project::query()
            ->accessibleBy(Auth::user())
            ->whereIn('id', $this->selectedIds)
            ->get();

        foreach ($projects as $project) {
            if (Auth::user()->can('update', $project)) {
                $project->update(['status' => $this->bulkStatus]);
            }
        }

        $this->selectedIds = [];
        $this->bulkStatus = '';
        $this->dispatch('toast', message: 'Bulk status updated.', tone: 'success');
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->editingProjectId = null;
        $this->domain = '';
        $this->niche = '';
        $this->cms = '';
        $this->start_date = '';
        $this->acquisition_cost = '0';
        $this->status = ProjectStatus::Setup->value;
        $this->notes = '';
        $this->owners = [];
        $this->resetValidation();
    }

    public function render(ProfitAndLossService $pnl)
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::query()
            ->accessibleBy(Auth::user())
            ->with(['owners', 'teamMembers'])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('domain', 'like', $term)
                        ->orWhere('niche', 'like', $term)
                        ->orWhere('cms', 'like', $term);
                });
            })
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy('domain')
            ->paginate(12);

        // Batch the month figures for the rows on this page. Calling the model's
        // per-project helpers from Blade cost several queries per row.
        $pageProjectIds = $projects->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return view('livewire.projects.index', [
            'projects' => $projects,
            'monthRows' => $pnl->monthRowsByProject(Auth::user(), now('UTC')->format('Y-m'), $pageProjectIds),
            'openTaskCounts' => Project::openTaskCountsFor($pageProjectIds),
            'statusOptions' => ProjectStatus::options(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'canManageOwnership' => Auth::user()->hasPermission('projects.manage_ownership'),
        ]);
    }
}
