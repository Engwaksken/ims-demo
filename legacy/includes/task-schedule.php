<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| My Tasks: periods and recurrence (pure helpers, no database access)
|--------------------------------------------------------------------------
|
| Recurring tasks are stored as one "rule" row (is_recurring = 1) with the
| ISO weekdays it repeats on in recurrence_days ("1,2,3,4,5,6,7" = every day,
| 1 = Monday). task_generate_recurring_occurrences() turns a rule into a real
| task for today; future dates are only shown as projections.
|
*/

if (!function_exists('task_repeat_presets')) {
    /** Repeat options offered in the form => ISO weekdays. */
    function task_repeat_presets(): array
    {
        return [
            'daily' => [1, 2, 3, 4, 5, 6, 7],
            'weekdays' => [1, 2, 3, 4, 5],
        ];
    }
}

if (!function_exists('task_repeat_days_from_input')) {
    /** Turn the form's repeat choice into a sorted list of ISO weekdays (empty = not recurring). */
    function task_repeat_days_from_input(string $repeat, array $customDays): array
    {
        $presets = task_repeat_presets();
        if (isset($presets[$repeat])) {
            return $presets[$repeat];
        }
        if ($repeat !== 'custom') {
            return [];
        }
        $days = array_values(array_unique(array_filter(
            array_map('intval', $customDays),
            static fn(int $day): bool => $day >= 1 && $day <= 7
        )));
        sort($days);
        return $days;
    }
}

if (!function_exists('task_repeat_preset_for')) {
    /** The form choice that matches stored weekdays: daily, weekdays or custom. */
    function task_repeat_preset_for(?string $recurrenceDays): string
    {
        $days = array_values(array_filter(array_map('intval', explode(',', (string)$recurrenceDays))));
        sort($days);
        foreach (task_repeat_presets() as $key => $preset) {
            if ($days === $preset) return $key;
        }
        return $days ? 'custom' : 'none';
    }
}

if (!function_exists('task_repeat_label')) {
    function task_repeat_label(?string $recurrenceDays): string
    {
        $preset = task_repeat_preset_for($recurrenceDays);
        if ($preset === 'daily') return 'Every day';
        if ($preset === 'weekdays') return 'Every weekday (Mon–Fri)';
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = array_filter(array_map('intval', explode(',', (string)$recurrenceDays)));
        return 'Every ' . implode(', ', array_map(static fn(int $day): string => $names[$day] ?? '', $days));
    }
}

if (!function_exists('task_rule_occurs_on')) {
    /** Does a recurring rule produce a task on $date? */
    function task_rule_occurs_on(array $rule, DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');
        if (($rule['status'] ?? 'Pending') !== 'Pending') return false;
        if ($day < (string)$rule['task_date']) return false;
        if (!empty($rule['recurrence_end_date']) && $day > (string)$rule['recurrence_end_date']) return false;
        $days = array_map('intval', explode(',', (string)($rule['recurrence_days'] ?? '')));
        return in_array((int)$date->format('N'), $days, true);
    }
}

if (!function_exists('task_period')) {
    /**
     * Resolve the period shown on the page.
     *
     * @return array{view:string,start:string,end:string,label:string,prev:?string,next:?string,anchor:string}
     */
    function task_period(string $view, string $today, ?string $anchor = null, ?string $from = null, ?string $to = null): array
    {
        $aliases = ['daily' => 'today', 'weekly' => 'week', 'past' => 'overdue'];
        $view = $aliases[$view] ?? $view;
        if (!in_array($view, ['today', 'week', 'month', 'range', 'overdue'], true)) $view = 'today';

        $valid = static function (?string $value): ?DateTimeImmutable {
            if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date && $date->format('Y-m-d') === $value ? $date : null;
        };
        $todayDate = $valid($today) ?? new DateTimeImmutable('today');
        $anchorDate = $valid($anchor) ?? $todayDate;

        switch ($view) {
            case 'week':
                $start = $anchorDate->modify('monday this week');
                $end = $start->modify('+6 days');
                return [
                    'view' => 'week', 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
                    'label' => 'Week of ' . $start->format('j M') . ' – ' . $end->format('j M Y'),
                    'prev' => $start->modify('-7 days')->format('Y-m-d'), 'next' => $start->modify('+7 days')->format('Y-m-d'),
                    'anchor' => $start->format('Y-m-d'),
                ];
            case 'month':
                $start = $anchorDate->modify('first day of this month');
                $end = $anchorDate->modify('last day of this month');
                return [
                    'view' => 'month', 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
                    'label' => $start->format('F Y'),
                    'prev' => $start->modify('-1 month')->format('Y-m-d'), 'next' => $start->modify('+1 month')->format('Y-m-d'),
                    'anchor' => $start->format('Y-m-d'),
                ];
            case 'range':
                $start = $valid($from) ?? $todayDate;
                $end = $valid($to) ?? $start->modify('+6 days');
                if ($end < $start) [$start, $end] = [$end, $start];
                // Keep the page fast: at most one year at a time.
                if ($start->diff($end)->days > 366) $end = $start->modify('+366 days');
                return [
                    'view' => 'range', 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
                    'label' => $start->format('j M Y') . ' – ' . $end->format('j M Y'),
                    'prev' => null, 'next' => null, 'anchor' => $start->format('Y-m-d'),
                ];
            case 'overdue':
                return [
                    'view' => 'overdue', 'start' => '1970-01-01', 'end' => $todayDate->modify('-1 day')->format('Y-m-d'),
                    'label' => 'Unfinished tasks from before today',
                    'prev' => null, 'next' => null, 'anchor' => $todayDate->format('Y-m-d'),
                ];
            default:
                $previous = $anchorDate->modify('-1 day');
                $nextDay = $anchorDate->modify('+1 day');
                return [
                    'view' => 'today', 'start' => $anchorDate->format('Y-m-d'), 'end' => $anchorDate->format('Y-m-d'),
                    'label' => $anchorDate == $todayDate ? 'Today, ' . $anchorDate->format('l j M Y') : $anchorDate->format('l j M Y'),
                    'prev' => $previous->format('Y-m-d'), 'next' => $nextDay->format('Y-m-d'),
                    'anchor' => $anchorDate->format('Y-m-d'),
                ];
        }
    }
}

if (!function_exists('task_projected_occurrences')) {
    /**
     * Upcoming copies of recurring rules for dates after today (they are only
     * created on the day itself). Dates that already have a real task for the
     * rule are skipped.
     *
     * @param list<array> $rules
     * @param array<string,true> $existing keys "ruleId|Y-m-d" that already exist
     * @return list<array>
     */
    function task_projected_occurrences(array $rules, string $start, string $end, string $today, array $existing = [], int $limit = 2000): array
    {
        $from = max($start, (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'));
        if ($from > $end) return [];
        $projected = [];
        $endDate = new DateTimeImmutable($end);
        foreach ($rules as $rule) {
            for ($date = new DateTimeImmutable($from); $date <= $endDate; $date = $date->modify('+1 day')) {
                if (!task_rule_occurs_on($rule, $date)) continue;
                $day = $date->format('Y-m-d');
                if (isset($existing[$rule['task_id'] . '|' . $day])) continue;
                $time = !empty($rule['task_due_at']) ? substr((string)$rule['task_due_at'], 11, 8) : null;
                $projected[] = $rule + [];
                $last = array_key_last($projected);
                $projected[$last]['task_date'] = $day;
                $projected[$last]['task_due_at'] = $time ? $day . ' ' . $time : null;
                $projected[$last]['status'] = 'Pending';
                $projected[$last]['is_projection'] = true;
                $projected[$last]['recurrence_parent_id'] = $rule['task_id'];
                if (count($projected) >= $limit) return $projected;
            }
        }
        return $projected;
    }
}
