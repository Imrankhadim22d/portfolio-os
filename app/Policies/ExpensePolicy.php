<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return FinancePolicy::viewExpenses($user);
    }

    public function view(User $user, Expense $expense): bool
    {
        if ($expense->expense_type === Expense::TYPE_PERSONAL) {
            return $user->isAdmin() || (int) $expense->owner_user_id === (int) $user->id;
        }

        if ($expense->is_shared) {
            return FinancePolicy::viewExpenses($user);
        }

        return $user->canAccessProject($expense->project) && FinancePolicy::viewExpenses($user);
    }

    public function create(User $user): bool
    {
        return FinancePolicy::manageExpenses($user);
    }

    public function update(User $user, Expense $expense): bool
    {
        if (! FinancePolicy::manageExpenses($user)) {
            return false;
        }

        if ($expense->expense_type === Expense::TYPE_PERSONAL) {
            return $user->isAdmin() || (int) $expense->owner_user_id === (int) $user->id;
        }

        return true;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->update($user, $expense);
    }
}
