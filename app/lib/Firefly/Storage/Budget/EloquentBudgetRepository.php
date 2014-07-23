<?php

namespace Firefly\Storage\Budget;


class EloquentBudgetRepository implements BudgetRepositoryInterface
{

    public function getAsSelectList()
    {
        $list = \Auth::user()->budgets()->with(
            ['limits', 'limits.limitrepetitions']
        )->orderBy('name', 'ASC')->get();
        $return = [];
        foreach ($list as $entry) {
            $return[intval($entry->id)] = $entry->name;
        }
        return $return;
    }

    public function store($data)
    {
        $budget = new \Budget;
        $budget->name = $data['name'];
        $budget->user()->associate(\Auth::user());
        $budget->save();

        // if limit, create limit (repetition itself will be picked up elsewhere).
        if ($data['amount'] > 0) {
            $limit = new \Limit;
            $limit->budget()->associate($budget);
            $startDate = new \Carbon\Carbon;
            switch ($data['repeat_freq']) {
                case 'daily':
                    $startDate->startOfDay();
                    break;
                case 'weekly':
                    $startDate->startOfWeek();
                    break;
                case 'monthly':
                    $startDate->startOfMonth();
                    break;
                case 'quarterly':
                    $startDate->firstOfQuarter();
                    break;
                case 'half-year':
                    $startDate->startOfYear();
                    if (intval($startDate->format('m')) >= 7) {
                        $startDate->addMonths(6);
                    }
                    break;
                case 'yearly':
                    $startDate->startOfYear();
                    break;
            }
            $limit->startdate = $startDate;
            $limit->amount = $data['amount'];
            $limit->repeats = $data['repeats'];
            $limit->repeat_freq = $data['repeat_freq'];
            $limit->save();
        }


        return $budget;
    }

    public function get()
    {
        return \Auth::user()->budgets()->with(
            ['limits' => function ($q) {
                    $q->orderBy('limits.startdate','ASC');
                }, 'limits.limitrepetitions' => function ($q) {
                        $q->orderBy('limit_repetitions.startdate','ASC');
                }]
        )->orderBy('name', 'ASC')->get();
    }

    public function find($id)
    {

        return \Auth::user()->budgets()->find($id);
    }

} 