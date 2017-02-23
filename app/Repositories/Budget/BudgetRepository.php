<?php
/**
 * BudgetRepository.php
 * Copyright (C) 2016 thegrumpydictator@gmail.com
 *
 * This software may be modified and distributed under the terms of the
 * Creative Commons Attribution-ShareAlike 4.0 International License.
 *
 * See the LICENSE file for details.
 */

declare(strict_types = 1);

namespace FireflyIII\Repositories\Budget;

use Carbon\Carbon;
use FireflyIII\Helpers\Collector\JournalCollectorInterface;
use FireflyIII\Models\AvailableBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Navigation;
use stdClass;

/**
 * Class BudgetRepository
 *
 * @package FireflyIII\Repositories\Budget
 */
class BudgetRepository implements BudgetRepositoryInterface
{
    /** @var User */
    private $user;

    /**
     * @return bool
     */
    public function cleanupBudgets(): bool
    {
        // delete limits with amount 0:
        BudgetLimit::where('amount', 0)->delete();

        return true;

    }

    /**
     * @param Budget $budget
     *
     * @return bool
     */
    public function destroy(Budget $budget): bool
    {
        $budget->delete();

        return true;
    }

    /**
     * Filters entries from the result set generated by getBudgetPeriodReport
     *
     * @param Collection $set
     * @param int        $budgetId
     * @param array      $periods
     *
     * @return array
     */
    public function filterAmounts(Collection $set, int $budgetId, array $periods): array
    {
        $arr  = [];
        $keys = array_keys($periods);
        foreach ($keys as $period) {
            /** @var stdClass $object */
            $result = $set->filter(
                function (TransactionJournal $object) use ($budgetId, $period) {
                    $result = strval($object->period_marker) === strval($period) && $budgetId === intval($object->budget_id);

                    return $result;
                }
            );
            $amount = '0';
            if (!is_null($result->first())) {
                $amount = $result->first()->sum_of_period;
            }

            $arr[$period] = $amount;
        }

        return $arr;
    }

    /**
     * Find a budget.
     *
     * @param int $budgetId
     *
     * @return Budget
     */
    public function find(int $budgetId): Budget
    {
        $budget = $this->user->budgets()->find($budgetId);
        if (is_null($budget)) {
            $budget = new Budget;
        }

        return $budget;
    }

    /**
     * Find a budget.
     *
     * @param string $name
     *
     * @return Budget
     */
    public function findByName(string $name): Budget
    {
        $budgets = $this->user->budgets()->get(['budgets.*']);
        /** @var Budget $budget */
        foreach ($budgets as $budget) {
            if ($budget->name === $name) {
                return $budget;
            }
        }

        return new Budget;
    }

    /**
     * This method returns the oldest journal or transaction date known to this budget.
     * Will cache result.
     *
     * @param Budget $budget
     *
     * @return Carbon
     */
    public function firstUseDate(Budget $budget): Carbon
    {
        $oldest  = Carbon::create()->startOfYear();
        $journal = $budget->transactionJournals()->orderBy('date', 'ASC')->first();
        if (!is_null($journal)) {
            $oldest = $journal->date < $oldest ? $journal->date : $oldest;
        }

        $transaction = $budget
            ->transactions()
            ->leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.id')
            ->orderBy('transaction_journals.date', 'ASC')->first(['transactions.*', 'transaction_journals.date']);
        if (!is_null($transaction)) {
            $carbon = new Carbon($transaction->date);
            $oldest = $carbon < $oldest ? $carbon : $oldest;
        }

        return $oldest;

    }

    /**
     * @return Collection
     */
    public function getActiveBudgets(): Collection
    {
        /** @var Collection $set */
        $set = $this->user->budgets()->where('active', 1)->get();

        $set = $set->sortBy(
            function (Budget $budget) {
                return strtolower($budget->name);
            }
        );

        return $set;
    }

    /**
     * @param Carbon $start
     * @param Carbon $end
     *
     * @return Collection
     */
    public function getAllBudgetLimits(Carbon $start, Carbon $end): Collection
    {
        $set = BudgetLimit::leftJoin('budgets', 'budgets.id', '=', 'budget_limits.budget_id')
                          ->with(['budget'])
                          ->where('budgets.user_id', $this->user->id)
                          ->where(
                              function (Builder $q5) use ($start, $end) {
                                  $q5->where(
                                      function (Builder $q1) use ($start, $end) {
                                          $q1->where(
                                              function (Builder $q2) use ($start, $end) {
                                                  $q2->where('budget_limits.end_date', '>=', $start->format('Y-m-d 00:00:00'));
                                                  $q2->where('budget_limits.end_date', '<=', $end->format('Y-m-d 00:00:00'));
                                              }
                                          )
                                             ->orWhere(
                                                 function (Builder $q3) use ($start, $end) {
                                                     $q3->where('budget_limits.start_date', '>=', $start->format('Y-m-d 00:00:00'));
                                                     $q3->where('budget_limits.start_date', '<=', $end->format('Y-m-d 00:00:00'));
                                                 }
                                             );
                                      }
                                  )
                                     ->orWhere(
                                         function (Builder $q4) use ($start, $end) {
                                             // or start is before start AND end is after end.
                                             $q4->where('budget_limits.start_date', '<=', $start->format('Y-m-d 00:00:00'));
                                             $q4->where('budget_limits.end_date', '>=', $end->format('Y-m-d 00:00:00'));
                                         }
                                     );
                              }
                          )->get(['budget_limits.*']);

        return $set;
    }

    /**
     * @param TransactionCurrency $currency
     * @param Carbon              $start
     * @param Carbon              $end
     *
     * @return string
     */
    public function getAvailableBudget(TransactionCurrency $currency, Carbon $start, Carbon $end): string
    {
        $amount          = '0';
        $availableBudget = $this->user->availableBudgets()
                                      ->where('transaction_currency_id', $currency->id)
                                      ->where('start_date', $start->format('Y-m-d'))
                                      ->where('end_date', $end->format('Y-m-d'))->first();
        if (!is_null($availableBudget)) {
            $amount = strval($availableBudget->amount);
        }

        return $amount;
    }

    /**
     * @param Budget $budget
     * @param Carbon $start
     * @param Carbon $end
     *
     * @return Collection
     */
    public function getBudgetLimits(Budget $budget, Carbon $start, Carbon $end): Collection
    {
        $set = $budget->budgetLimits()
                      ->where(
                          function (Builder $q5) use ($start, $end) {
                              $q5->where(
                                  function (Builder $q1) use ($start, $end) {
                                      // budget limit ends within period
                                      $q1->where(
                                          function (Builder $q2) use ($start, $end) {
                                              $q2->where('budget_limits.end_date', '>=', $start->format('Y-m-d 00:00:00'));
                                              $q2->where('budget_limits.end_date', '<=', $end->format('Y-m-d 00:00:00'));
                                          }
                                      )
                                          // budget limit start within period
                                         ->orWhere(
                                              function (Builder $q3) use ($start, $end) {
                                                  $q3->where('budget_limits.start_date', '>=', $start->format('Y-m-d 00:00:00'));
                                                  $q3->where('budget_limits.start_date', '<=', $end->format('Y-m-d 00:00:00'));
                                              }
                                          );
                                  }
                              )
                                 ->orWhere(
                                     function (Builder $q4) use ($start, $end) {
                                         // or start is before start AND end is after end.
                                         $q4->where('budget_limits.start_date', '<=', $start->format('Y-m-d 00:00:00'));
                                         $q4->where('budget_limits.end_date', '>=', $end->format('Y-m-d 00:00:00'));
                                     }
                                 );
                          }
                      )->orderBy('budget_limits.start_date', 'DESC')->get(['budget_limits.*']);

        return $set;
    }

    /**
     * This method is being used to generate the budget overview in the year/multi-year report. Its used
     * in both the year/multi-year budget overview AND in the accompanying chart.
     *
     * @param Collection $budgets
     * @param Collection $accounts
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return array
     */
    public function getBudgetPeriodReport(Collection $budgets, Collection $accounts, Carbon $start, Carbon $end): array
    {
        $carbonFormat = Navigation::preferredCarbonFormat($start, $end);
        $data         = [];
        // prep data array:
        /** @var Budget $budget */
        foreach ($budgets as $budget) {
            $data[$budget->id] = [
                'name'    => $budget->name,
                'sum'     => '0',
                'entries' => [],
            ];
        }

        // get all transactions:
        /** @var JournalCollectorInterface $collector */
        $collector = app(JournalCollectorInterface::class);
        $collector->setAccounts($accounts)->setRange($start, $end);
        $collector->setBudgets($budgets);
        $transactions = $collector->getJournals();

        // loop transactions:
        /** @var Transaction $transaction */
        foreach ($transactions as $transaction) {
            $budgetId                          = max(intval($transaction->transaction_journal_budget_id), intval($transaction->transaction_budget_id));
            $date                              = $transaction->date->format($carbonFormat);
            $data[$budgetId]['entries'][$date] = bcadd($data[$budgetId]['entries'][$date] ?? '0', $transaction->transaction_amount);
        }

        return $data;

    }

    /**
     * @return Collection
     */
    public function getBudgets(): Collection
    {
        /** @var Collection $set */
        $set = $this->user->budgets()->get();

        $set = $set->sortBy(
            function (Budget $budget) {
                return strtolower($budget->name);
            }
        );

        return $set;
    }

    /**
     * @return Collection
     */
    public function getInactiveBudgets(): Collection
    {
        /** @var Collection $set */
        $set = $this->user->budgets()->where('active', 0)->get();

        $set = $set->sortBy(
            function (Budget $budget) {
                return strtolower($budget->name);
            }
        );

        return $set;
    }

    /**
     * @param Collection $accounts
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return array
     */
    public function getNoBudgetPeriodReport(Collection $accounts, Carbon $start, Carbon $end): array
    {
        $carbonFormat = Navigation::preferredCarbonFormat($start, $end);
        /** @var JournalCollectorInterface $collector */
        $collector = app(JournalCollectorInterface::class);
        $collector->setAccounts($accounts)->setRange($start, $end);
        $collector->setTypes([TransactionType::WITHDRAWAL]);
        $collector->withoutBudget();
        $transactions = $collector->getJournals();
        $result       = [
            'entries' => [],
            'name'    => strval(trans('firefly.no_budget')),
            'sum'     => '0',
        ];

        foreach ($transactions as $transaction) {
            $date = $transaction->date->format($carbonFormat);

            if (!isset($result['entries'][$date])) {
                $result['entries'][$date] = '0';
            }
            $result['entries'][$date] = bcadd($result['entries'][$date], $transaction->transaction_amount);
        }

        return $result;
    }

    /**
     * @param TransactionCurrency $currency
     * @param Carbon              $start
     * @param Carbon              $end
     * @param string              $amount
     *
     * @return bool
     */
    public function setAvailableBudget(TransactionCurrency $currency, Carbon $start, Carbon $end, string $amount): bool
    {
        $availableBudget = $this->user->availableBudgets()
                                      ->where('transaction_currency_id', $currency->id)
                                      ->where('start_date', $start->format('Y-m-d'))
                                      ->where('end_date', $end->format('Y-m-d'))->first();
        if (is_null($availableBudget)) {
            $availableBudget = new AvailableBudget;
            $availableBudget->user()->associate($this->user);
            $availableBudget->transactionCurrency()->associate($currency);
            $availableBudget->start_date = $start;
            $availableBudget->end_date   = $end;
        }
        $availableBudget->amount = $amount;
        $availableBudget->save();

        return true;
    }

    /**
     * @param User $user
     */
    public function setUser(User $user)
    {
        $this->user = $user;
    }

    /**
     * @param Collection $budgets
     * @param Collection $accounts
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return string
     */
    public function spentInPeriod(Collection $budgets, Collection $accounts, Carbon $start, Carbon $end): string
    {
        /** @var JournalCollectorInterface $collector */
        $collector = app(JournalCollectorInterface::class);
        $collector->setUser($this->user);
        $collector->setRange($start, $end)->setTypes([TransactionType::WITHDRAWAL])->setBudgets($budgets);

        if ($accounts->count() > 0) {
            $collector->setAccounts($accounts);
        }
        if ($accounts->count() === 0) {
            $collector->setAllAssetAccounts();
        }

        $set = $collector->getJournals();
        $sum = strval($set->sum('transaction_amount'));

        return $sum;
    }

    /**
     * @param Collection $accounts
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return string
     */
    public function spentInPeriodWoBudget(Collection $accounts, Carbon $start, Carbon $end): string
    {
        /** @var JournalCollectorInterface $collector */
        $collector = app(JournalCollectorInterface::class);
        $collector->setUser($this->user);
        $collector->setRange($start, $end)->setTypes([TransactionType::WITHDRAWAL])->withoutBudget();

        if ($accounts->count() > 0) {
            $collector->setAccounts($accounts);
        }
        if ($accounts->count() === 0) {
            $collector->setAllAssetAccounts();
        }

        $set = $collector->getJournals();
        $set = $set->filter(
            function (Transaction $transaction) {
                if (bccomp($transaction->transaction_amount, '0') === -1) {
                    return $transaction;
                }

                return null;
            }
        );

        $sum = strval($set->sum('transaction_amount'));

        return $sum;
    }

    /**
     * @param array $data
     *
     * @return Budget
     */
    public function store(array $data): Budget
    {
        $newBudget = new Budget(
            [
                'user_id' => $this->user->id,
                'name'    => $data['name'],
            ]
        );
        $newBudget->save();

        return $newBudget;
    }

    /**
     * @param Budget $budget
     * @param array  $data
     *
     * @return Budget
     */
    public function update(Budget $budget, array $data): Budget
    {
        // update the account:
        $budget->name   = $data['name'];
        $budget->active = $data['active'];
        $budget->save();

        return $budget;
    }

    /**
     * @param Budget $budget
     * @param Carbon $start
     * @param Carbon $end
     * @param int    $amount
     *
     * @return BudgetLimit
     */
    public function updateLimitAmount(Budget $budget, Carbon $start, Carbon $end, int $amount): BudgetLimit
    {
        // there might be a budget limit for these dates:
        /** @var BudgetLimit $limit */
        $limit = $budget->budgetlimits()
                        ->where('budget_limits.start_date', $start->format('Y-m-d'))
                        ->where('budget_limits.end_date', $end->format('Y-m-d'))
                        ->first(['budget_limits.*']);

        // delete if amount is zero.
        if (!is_null($limit) && $amount <= 0.0) {
            $limit->delete();

            return new BudgetLimit;
        }
        // update if exists:
        if (!is_null($limit)) {
            $limit->amount = $amount;
            $limit->save();

            return $limit;
        }

        // or create one and return it.
        $limit = new BudgetLimit;
        $limit->budget()->associate($budget);
        $limit->start_date = $start;
        $limit->end_date   = $end;
        $limit->amount     = $amount;
        $limit->save();

        return $limit;
    }
}
