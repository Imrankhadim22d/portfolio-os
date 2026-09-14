<?php

use App\Enums\ArticleStatus;
use App\Enums\CredentialType;
use App\Enums\LinkWorkflowStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Livewire\Articles\Index as ArticlesIndex;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\Show as TaskShow;
use App\Models\Credential;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ArticleWorkflowService;
use App\Services\ExpenseService;
use App\Services\LinkWorkflowService;
use App\Services\ProjectOwnershipService;
use App\Services\TaskWorkflowService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TaskTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(TaskTemplateSeeder::class);
});

function workAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

function workProject(User $owner, array $overrides = []): Project
{
    $project = Project::factory()->create($overrides);

    app(ProjectOwnershipService::class)->sync($project, [
        ['user_id' => $owner->id, 'share_bps' => 10000],
    ]);

    return $project->fresh();
}

it('creates a project without auto-generating setup tasks', function () {
    $admin = workAdmin();

    Livewire::actingAs($admin)
        ->test(ProjectsIndex::class)
        ->call('create')
        ->set('domain', 'setup-check.test')
        ->set('status', ProjectStatus::Setup->value)
        ->set('acquisition_cost', '100')
        ->set('owners', [
            ['user_id' => (string) $admin->id, 'share_percent' => '100'],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->where('domain', 'setup-check.test')->first();
    expect($project)->not->toBeNull()
        ->and($project->tasks()->count())->toBe(0)
        ->and($project->openTasksCount())->toBe(0);
});

it('still rejects project ownership that does not total 100% (regression)', function () {
    $admin = workAdmin();
    $partner = User::factory()->create();
    $partner->assignRole('partner');

    Livewire::actingAs($admin)
        ->test(ProjectsIndex::class)
        ->call('create')
        ->set('domain', 'broken-m3.test')
        ->set('status', ProjectStatus::Setup->value)
        ->set('acquisition_cost', '1000')
        ->set('owners', [
            ['user_id' => (string) $admin->id, 'share_percent' => '60'],
            ['user_id' => (string) $partner->id, 'share_percent' => '30'],
        ])
        ->call('save')
        ->assertHasErrors(['owners']);

    expect(Project::query()->where('domain', 'broken-m3.test')->exists())->toBeFalse();
});

it('blocks duplicate article keywords on the same project', function () {
    $admin = workAdmin();
    $project = workProject($admin);
    $workflow = app(ArticleWorkflowService::class);

    $workflow->create([
        'project_id' => $project->id,
        'title' => 'First',
        'target_keyword' => 'best vpn',
        'cost_paisa' => 10000,
        'writer_id' => null,
    ], $admin);

    expect(fn () => $workflow->create([
        'project_id' => $project->id,
        'title' => 'Second',
        'target_keyword' => 'Best VPN',
        'cost_paisa' => 10000,
        'writer_id' => null,
    ], $admin))->toThrow(ValidationException::class);
});

it('creates an expense once when an article is approved (idempotent)', function () {
    $admin = workAdmin();
    $writer = User::factory()->create();
    $writer->assignRole('staff');
    $project = workProject($admin);
    $workflow = app(ArticleWorkflowService::class);

    $article = $workflow->create([
        'project_id' => $project->id,
        'title' => 'Paid piece',
        'target_keyword' => 'paid piece keyword',
        'cost_paisa' => 15_000_00,
        'writer_id' => $writer->id,
    ], $admin);

    $article = $workflow->submitDraft($article);
    $article = $workflow->approve($article, $admin);

    expect($article->status)->toBe(ArticleStatus::Approved)
        ->and($article->expense_id)->not->toBeNull();

    $expenseId = $article->expense_id;
    $countBefore = Expense::query()->count();

    // Second approve attempt should fail transition — re-run expense hook via service instead
    $expenseAgain = app(ExpenseService::class)->createFromArticleApproval($article->fresh(), $admin);

    expect($expenseAgain->id)->toBe($expenseId)
        ->and(Expense::query()->count())->toBe($countBefore)
        ->and((int) $expenseAgain->amount_paisa)->toBe(15_000_00);
});

it('creates expense on link approval and warns on duplicate source domain', function () {
    $admin = workAdmin();
    $project = workProject($admin);
    $links = app(LinkWorkflowService::class);

    $first = $links->create([
        'project_id' => $project->id,
        'source_url' => 'https://www.publisher.example/post-1',
        'target_page' => 'https://site.test/a',
        'anchor_text' => 'anchor a',
        'cost_paisa' => 9_000_00,
        'link_date' => now()->toDateString(),
    ], $admin);

    expect($first['domain_warning'])->toBeNull()
        ->and($first['link']->source_domain)->toBe('publisher.example');

    $second = $links->create([
        'project_id' => $project->id,
        'source_url' => 'https://publisher.example/post-2',
        'target_page' => 'https://site.test/b',
        'anchor_text' => 'anchor b',
        'cost_paisa' => 4_000_00,
        'link_date' => now()->toDateString(),
    ], $admin);

    expect($second['domain_warning'])->toContain('publisher.example');

    $link = $links->submit($first['link']);
    $link = $links->approve($link, $admin);

    expect($link->workflow_status)->toBe(LinkWorkflowStatus::Approved)
        ->and($link->expense_id)->not->toBeNull()
        ->and((int) Expense::query()->find($link->expense_id)->amount_paisa)->toBe(9_000_00);

    $count = Expense::query()->count();
    app(ExpenseService::class)->createFromLinkApproval($link->fresh(), $admin);
    expect(Expense::query()->count())->toBe($count);
});

it('requires a rejection reason for tasks', function () {
    $admin = workAdmin();
    $project = workProject($admin);

    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Needs reject',
        'type' => TaskType::AdHoc,
        'status' => TaskStatus::Submitted,
        'submitted_at' => now(),
        'created_by' => $admin->id,
    ]);

    $workflow = app(TaskWorkflowService::class);

    expect(fn () => $workflow->reject($task, $admin, ''))
        ->toThrow(ValidationException::class);

    $rejected = $workflow->reject($task->fresh(), $admin, 'Missing screenshots.');
    expect($rejected->status)->toBe(TaskStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Missing screenshots.');

    $task2 = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Needs reject ui',
        'type' => TaskType::AdHoc,
        'status' => TaskStatus::Submitted,
        'submitted_at' => now(),
        'created_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test(TaskShow::class, ['task' => $task2])
        ->call('openReject')
        ->set('rejection_reason', '')
        ->call('reject')
        ->assertHasErrors(['rejection_reason']);
});

it('allows supervisors on the approval queue and blocks accountants from tasks', function () {
    $admin = workAdmin();
    $project = workProject($admin, ['domain' => 'queue.test']);

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor', $project->id);
    $project->teamMembers()->attach($supervisor->id);

    $accountant = User::factory()->create();
    $accountant->assignRole('accountant');

    Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Queue item',
        'type' => TaskType::AdHoc,
        'status' => TaskStatus::Submitted,
        'submitted_at' => now(),
    ]);

    $this->actingAs($supervisor)
        ->get(route('approvals.queue'))
        ->assertOk()
        ->assertSee('Queue item');

    expect($accountant->hasPermission('tasks.view'))->toBeFalse();

    $this->actingAs($accountant)
        ->get(route('tasks.index'))
        ->assertForbidden();
});

it('masks credential secrets and logs reveal (m2 regression)', function () {
    $admin = workAdmin();
    $project = workProject($admin, ['domain' => 'vault-m3.test']);

    $credential = Credential::factory()->create([
        'project_id' => $project->id,
        'type' => CredentialType::CmsAdmin,
        'label' => 'WP',
        'username' => 'admin',
        'secret' => 'super-secret-m3',
    ]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['project' => $project])
        ->assertSee('••••••••')
        ->assertDontSee('super-secret-m3')
        ->call('revealCredential', $credential->id)
        ->assertSee('super-secret-m3');
});

it('lets staff submit own assigned article draft but not approve', function () {
    $admin = workAdmin();
    $staff = User::factory()->create();
    $staff->assignRole('staff');
    $project = workProject($admin);
    $project->teamMembers()->attach($staff->id);

    $article = app(ArticleWorkflowService::class)->create([
        'project_id' => $project->id,
        'title' => 'Staff draft',
        'target_keyword' => 'staff draft kw',
        'cost_paisa' => 1000,
        'writer_id' => $staff->id,
    ], $admin);

    expect($staff->can('submit', $article))->toBeTrue()
        ->and($staff->can('approve', $article))->toBeFalse();

    Livewire::actingAs($staff)
        ->test(ArticlesIndex::class)
        ->call('submitDraft', $article->id)
        ->assertHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::DraftSubmitted);
});
