<?php
/**
 * Created by PhpStorm.
 * User: sander
 * Date: 20/07/14
 * Time: 13:43
 */

namespace Firefly\Storage\Limit;


class EloquentLimitRepository implements LimitRepositoryInterface
{

    public function find($limitId)
    {
        return \Limit::with('limitrepetitions')->where('limits.id', $limitId)->leftJoin('components', 'components.id', '=', 'limits.component_id')
            ->where('components.user_id', \Auth::user()->id)->first(['limits.*']);
    }

    public function store($data)
    {
        $budget = \Budget::find($data['budget_id']);
        if (is_null($budget)) {
            \Session::flash('error', 'No such budget.');
            return new \Limit;
        }
        // set the date to the correct start period:
        $date = new \Carbon\Carbon($data['startdate']);
        switch ($data['period']) {
            case 'daily':
                $date->startOfDay();
                break;
            case 'weekly':
                $date->startOfWeek();
                break;
            case 'monthly':
                $date->startOfMonth();
                break;
            case 'quarterly':
                $date->firstOfQuarter();
                break;
            case 'half-year':

                if (intval($date->format('m')) >= 7) {
                    $date->startOfYear();
                    $date->addMonths(6);
                } else {
                    $date->startOfYear();
                }
                break;
            case 'yearly':
                $date->startOfYear();
                break;
        }
        // find existing:
        $count = \Limit::
            leftJoin('components', 'components.id', '=', 'limits.component_id')->where(
                'components.user_id', \Auth::user()->id
            )->where('startdate', $date->format('Y-m-d'))->where('component_id', $data['budget_id'])->where(
                'repeat_freq', $data['period']
            )->count();
        if ($count > 0) {
            \Session::flash('error', 'There already is an entry for these parameters.');
            return new \Limit;
        }
        // create new limit:
        $limit = new \Limit;
        $limit->budget()->associate($budget);
        $limit->startdate = $date;
        $limit->amount = floatval($data['amount']);
        $limit->repeats = isset($data['repeats']) ? intval($data['repeats']) : 0;
        $limit->repeat_freq = $data['period'];
        if (!$limit->save()) {
            Session::flash('error', 'Could not save: ' . $limit->errors()->first());
        }
        return $limit;
    }

    public function getTJByBudgetAndDateRange(\Budget $budget, \Carbon\Carbon $start, \Carbon\Carbon $end)
    {
        $type = \TransactionType::where('type', 'Withdrawal')->first();

        $result = $budget->transactionjournals()->after($start)->before($end)->get();

        return $result;

    }

} 