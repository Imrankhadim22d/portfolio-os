<?php

use App\Enums\ArticleStatus;
use App\Enums\DistributionStatus;
use App\Enums\RevenueSource;
use App\Livewire\Money\ExpensesIndex;
use App\Livewire\Money\RevenuesIndex;
use App\Models\Article;
use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\PartnerLedgerEntry;
use App\Models\Project;
use App\Models\Revenue;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\ExpenseService;
use App\Services\PartnerLedgerService;
use App\Services\ProfitAndLossService;
use App\Services\ProjectOwnershipService;
use App\Services\RevenueService;
use App\Support\Currency;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function moneyAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

function moneyAccountant(): User
{
    $user = User::factory()->create();
    $user->assignRole('accountant');

    return $user;
}

function moneyPartner(): User
{
    $user = User::factory()->create();
    $user->assignRole('partner');

    return $user;
}

function moneyProject(User $owner, array $overrides = []): Project
{
    $project = Project::factory()->create($overrides);
    app(ProjectOwnershipService::class)->sync($project, [
        ['user_id' => $owner->id, 'share_bps' => 10000],
    ]);

    return $project->fresh();
}

it('freezes revenue FX on the record and does not recalculate later', function () {
    $admin = moneyAdmin();
    $project = moneyProject($admin);

    $service = app(RevenueService::class);
    $rev = $service->create([
        'project_id' => $project->id,
        'period_month' => '2026-06',
        'source' => RevenueSource::Adsense->value,
        'amount_usd' => '100.00',
        'fx_rate' => '280.00',
    ], $admin);

    expect($rev->amount_usd_cents)->toBe(100_00)
        ->and($rev->fx_rate_e6)->toBe(Money::fxRateToE6('280.00'))
        ->and($rev->amount_pkr_paisa)->toBe(Money::sourceMinorToBaseMinor(100_00, Money::fxRateToE6('280.00')));

    $frozen = $rev->amount_pkr_paisa;

    // "New market rate" must not change the stored base amount when only notes are updated
    $updated = $service->update($rev, [
        'notes' => 'touched later',
        'amount_usd_cents' => $rev->amount_usd_cents,
        'fx_rate_e6' => $rev->fx_rate_e6,
    ], $admin);

    expect($updated->amount_pkr_paisa)->toBe($frozen)
        ->and($updated->fx_rate_e6)->toBe(Money::fxRateToE6('280.00'));

    // If amount/fx explicitly edited, the base amount re-freezes from the new pair (still stored, not live tick)
    $refrozen = $service->update($rev->fresh(), [
        'amount_usd' => '100.00',
        'fx_rate' => '300.00',
    ], $admin);

    expect($refrozen->amount_pkr_paisa)->toBe(Money::sourceMinorToBaseMinor(100_00, Money::fxRateToE6('300.00')))
        ->and($refrozen->amount_pkr_paisa)->not->toBe($frozen);
});

it('allocates shared expenses by revenue share', function () {
    $admin = moneyAdmin();
    $a = moneyProject($admin, ['domain' => 'alpha.test', 'status' => 'monetized']);
    $b = moneyProject($admin, ['domain' => 'beta.test', 'status' => 'live']);

    $rev = app(RevenueService::class);
    $rev->create([
        'project_id' => $a->id,
        'period_month' => '2026-07',
        'source' => RevenueSource::Adsense->value,
        'amount_usd_cents' => 0,
        'fx_rate_e6' => 0,
        'amount_pkr' => '3000',
        'currency_input' => Currency::code(),
    ], $admin);
    // Override via direct create — exercises the base-currency-only input path
    Revenue::query()->where('project_id', $a->id)->delete();
    Revenue::query()->create([
        'project_id' => $a->id,
        'period_month' => '2026-07-01',
        'source' => RevenueSource::Adsense,
        'amount_usd_cents' => 0,
        'fx_rate_e6' => 0,
        'amount_pkr_paisa' => 300_000_00, // 300,000 major units
        'currency_input' => Currency::code(),
        'created_by' => $admin->id,
    ]);
    Revenue::query()->create([
        'project_id' => $b->id,
        'period_month' => '2026-07-01',
        'source' => RevenueSource::Adsense,
        'amount_usd_cents' => 0,
        'fx_rate_e6' => 0,
        'amount_pkr_paisa' => 100_000_00, // 100,000 major units
        'currency_input' => Currency::code(),
        'created_by' => $admin->id,
    ]);

    $expense = app(ExpenseService::class)->createManual([
        'is_shared' => true,
        'amount' => '40000',
        'description' => 'Shared tools',
        'expense_date' => '2026-07-15',
        'is_paid' => true,
    ], $admin);

    $allocA = ExpenseAllocation::query()->where('expense_id', $expense->id)->where('project_id', $a->id)->first();
    $allocB = ExpenseAllocation::query()->where('expense_id', $expense->id)->where('project_id', $b->id)->first();

    // 75% / 25% of 4_000_000 paisa
    expect($allocA)->not->toBeNull()
        ->and($allocB)->not->toBeNull()
        ->and((int) $allocA->amount_paisa)->toBe(3_000_000)
        ->and((int) $allocB->amount_paisa)->toBe(1_000_000)
        ->and((int) $allocA->amount_paisa + (int) $allocB->amount_paisa)->toBe(4_000_000);
});

it('locks distribution on approve with ownership snapshot and partner ledger credit', function () {
    $admin = moneyAdmin();
    $partner = moneyPartner();
    $project = Project::factory()->create(['domain' => 'own.test', 'status' => 'monetized']);
    app(ProjectOwnershipService::class)->sync($project, [
        ['user_id' => $admin->id, 'share_bps' => 6000],
        ['user_id' => $partner->id, 'share_bps' => 4000],
    ]);

    Revenue::query()->create([
        'project_id' => $project->id,
        'period_month' => '2026-05-01',
        'source' => RevenueSource::Adsense,
        'amount_usd_cents' => 0,
        'fx_rate_e6' => 0,
        'amount_pkr_paisa' => 100_000_00,
        'currency_input' => Currency::code(),
        'created_by' => $admin->id,
    ]);

    $dist = app(DistributionService::class);
    $run = $dist->createDraft('2026-05', $admin, holdbackBps: 0);

    expect($run->status)->toBe(DistributionStatus::Draft)
        ->and($run->ownership_snapshot)->not->toBeEmpty();

    $approved = $dist->approve($run, $admin);
    expect($approved->status)->toBe(DistributionStatus::Approved)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->ownership_snapshot)->not->toBeEmpty();

    $partnerCredit = PartnerLedgerEntry::query()
        ->where('user_id', $partner->id)
        ->where('distribution_run_id', $approved->id)
        ->first();

    expect($partnerCredit)->not->toBeNull()
        ->and((int) $partnerCredit->amount_paisa)->toBe(40_000_00); // 40% of 100k

    $balance = app(PartnerLedgerService::class)->balanceFor($partner->id);
    expect($balance)->toBe(40_000_00);

    // Cannot re-approve / treat as editable
    expect(fn () => $dist->approve($approved->fresh(), $admin))->toThrow(RuntimeException::class);
});

it('smoke-exports revenue and expense CSV for finance users', function () {
    $admin = moneyAdmin();
    $project = moneyProject($admin);

    app(RevenueService::class)->create([
        'project_id' => $project->id,
        'period_month' => now()->format('Y-m'),
        'source' => RevenueSource::Adsense->value,
        'amount_usd' => '10',
        'fx_rate' => '278',
    ], $admin);

    Livewire::actingAs($admin)
        ->test(RevenuesIndex::class)
        ->call('exportCsv')
        ->assertSuccessful();

    Livewire::actingAs($admin)
        ->test(ExpensesIndex::class)
        ->call('exportCsv')
        ->assertSuccessful();
});

it('creates a personal expense with owner_user_id and keeps it private to the owner and admin visibility path', function () {
    $admin = moneyAdmin();
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $expense = app(ExpenseService::class)->createManual([
        'expense_type' => Expense::TYPE_PERSONAL,
        'amount' => '2500',
        'description' => 'Office coffee',
        'expense_date' => '2026-07-15',
        'is_paid' => false,
    ], $owner);

    expect($expense->expense_type)->toBe(Expense::TYPE_PERSONAL)
        ->and($expense->project_id)->toBeNull()
        ->and((int) $expense->owner_user_id)->toBe((int) $owner->id);

    expect(Expense::query()->accessibleBy($owner)->whereKey($expense->id)->exists())->toBeTrue()
        ->and(Expense::query()->accessibleBy($other)->whereKey($expense->id)->exists())->toBeFalse()
        ->and(Expense::query()->accessibleBy($admin)->whereKey($expense->id)->exists())->toBeTrue();
});

it('allows accountant finance access and blocks partner mutations', function () {
    $accountant = moneyAccountant();
    $partner = moneyPartner();
    $admin = moneyAdmin();
    $project = moneyProject($admin);

    expect($accountant->hasPermission('revenue.manage'))->toBeTrue()
        ->and($accountant->hasPermission('tasks.view'))->toBeFalse()
        ->and($accountant->hasPermission('credentials.view'))->toBeFalse();

    $this->actingAs($accountant)
        ->get(route('money.revenues'))
        ->assertOk();

    $this->actingAs($accountant)
        ->get(route('money.expenses'))
        ->assertOk();

    $this->actingAs($accountant)
        ->get(route('money.pnl'))
        ->assertOk();

    expect($partner->hasPermission('revenue.view'))->toBeTrue()
        ->and($partner->hasPermission('revenue.manage'))->toBeFalse()
        ->and($partner->hasPermission('expenses.manage'))->toBeFalse();

    $this->actingAs($partner)
        ->get(route('money.revenues'))
        ->assertOk();

    // Partner cannot manage revenue via Livewire
    Livewire::actingAs($partner)
        ->test(RevenuesIndex::class)
        ->call('create')
        ->assertForbidden();
});

it('still creates article expense only once (idempotent) after money expansion', function () {
    $admin = moneyAdmin();
    $project = moneyProject($admin);

    $article = Article::query()->create([
        'project_id' => $project->id,
        'title' => 'Money test article',
        'target_keyword' => 'unique-kw-money',
        'status' => ArticleStatus::Approved,
        'cost_paisa' => 12_000_00,
        'created_by' => $admin->id,
        'approved_by' => $admin->id,
        'approved_at' => now(),
        'publish_date' => now()->toDateString(),
    ]);

    $svc = app(ExpenseService::class);
    $first = $svc->createFromArticleApproval($article, $admin);
    $second = $svc->createFromArticleApproval($article->fresh(), $admin);

    expect($first->id)->toBe($second->id)
        ->and(Expense::query()->where('source_type', Article::class)->where('source_id', $article->id)->count())->toBe(1)
        ->and((int) $first->amount_paisa)->toBe(12_000_00)
        ->and((bool) $first->is_shared)->toBeFalse();
});

it('computes portfolio pnl for a month', function () {
    $admin = moneyAdmin();
    $p = moneyProject($admin, ['status' => 'monetized']);

    Revenue::query()->create([
        'project_id' => $p->id,
        'period_month' => '2026-04-01',
        'source' => RevenueSource::Sale,
        'amount_usd_cents' => 0,
        'fx_rate_e6' => 0,
        'amount_pkr_paisa' => 50_000_00,
        'currency_input' => Currency::code(),
        'created_by' => $admin->id,
    ]);

    app(ExpenseService::class)->createManual([
        'project_id' => $p->id,
        'amount' => '10000',
        'description' => 'Direct',
        'expense_date' => '2026-04-10',
    ], $admin);

    $report = app(ProfitAndLossService::class)->forMonth($admin, '2026-04');
    expect($report['totals']['revenue_paisa'])->toBe(50_000_00)
        ->and($report['totals']['direct_expense_paisa'])->toBe(10_000_00)
        ->and($report['totals']['net_profit_paisa'])->toBe(40_000_00);
});
