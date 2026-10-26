<?php
/** Stop-specific scheduled departures; never treat a missing prediction as proof of departure. */
if (!defined('ABSPATH')) exit;

function dxb_schedule_day($name, $day) {
    $name = strtolower(trim($name));
    $days = ['mon'=>1, 'tue'=>2, 'wed'=>3, 'thu'=>4, 'fri'=>5, 'sat'=>6, 'sun'=>7];
    if ($day < 1 || $day > 7) return false;
    if (preg_match('/\b(daily|every day)\b/', $name)) return true;
    $dayPattern = '(?:mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)s?';
    // Expand ranges before checking individual days: “Monday to Friday” includes Thursday.
    $matches = [];
    preg_match_all('/\b(' . $dayPattern . ')\s*(?:to|[-–—])\s*(' . $dayPattern . ')\b/u', $name, $matches, PREG_SET_ORDER);
    foreach ($matches as $range) {
        $start = $days[substr($range[1], 0, 3)];
        $end = $days[substr($range[2], 0, 3)];
        if ($start <= $end ? ($day >= $start && $day <= $end) : ($day >= $start || $day <= $end)) return true;
    }
    $remaining = preg_replace('/\b' . $dayPattern . '\s*(?:to|[-–—])\s*' . $dayPattern . '\b/u', '', $name);
    preg_match_all('/\b(' . $dayPattern . ')\b/', $remaining, $matches);
    foreach ($matches[1] as $single) {
        if ($days[substr($single, 0, 3)] === $day) return true;
    }
    return false;
}

function dxb_resolve_timetable($data, DateTimeImmutable $now, array $holidays) {
    $zone = new DateTimeZone('Europe/London');
    $now = $now->setTimezone($zone);
    $base = $now->setTime(0, 0);
    $departures = [];
    $today = [];
    for ($offset = -1; $offset <= 7; $offset++) {
        $date = $base->modify("$offset days");
        $day = in_array($date->format('Y-m-d'), $holidays, true) ? 7 : (int)$date->format('N');
        foreach ($data['timetable']['routes'] ?? [] as $route) {
            $variants = [];
            foreach ($route['schedules'] ?? [] as $schedule) {
                if (!dxb_schedule_day($schedule['name'] ?? '', $day)) continue;
                if (empty($schedule['knownJourneys'])) return ['available'=>false];
                $times = [];
                foreach ($schedule['knownJourneys'] as $journey) {
                    $hour = (int)$journey['hour']; $minute = (int)$journey['minute'];
                    if ($hour < 3) $hour += 24;
                    $times[] = $hour * 60 + $minute;
                }
                sort($times); $variants[implode(',', $times)] = $times;
            }
            // School/holiday calendars that disagree require more data: do not guess.
            if (count($variants) > 1) return ['available'=>false];
            foreach ($variants as $times) foreach ($times as $minute) {
                $time = $date->modify('+' . intdiv($minute, 1440) . ' days')->setTime(intdiv($minute % 1440, 60), $minute % 60);
                if ($time->format('Y-m-d') === $now->format('Y-m-d')) $today[] = $time->getTimestamp();
                if ($time > $now) $departures[] = $time->getTimestamp();
            }
        }
    }
    sort($departures); sort($today);
    if (!$departures) return ['available'=>false];
    return ['available'=>true,'ended'=>count($today)>0 && end($today)<$now->getTimestamp(),'next'=>wp_date('c',$departures[0],$zone),'last'=>count($today)?wp_date('H:i',end($today),$zone):null];
}

add_action('rest_api_init', static function () {
    register_rest_route('dx-buses/v1', '/timetable/(?P<route>[a-zA-Z0-9]{1,6})/(?P<stop>[a-zA-Z0-9]{5,30})', [
        'methods'=>'GET', 'permission_callback'=>'__return_true',
        'callback'=>static function ($request) {
            $route = strtolower($request['route']);
            $valid = dxb_route_exists($route); if (is_wp_error($valid)) return $valid;
            $data = dxb_tfl('/Line/'.rawurlencode($route).'/Timetable/'.rawurlencode($request['stop']), HOUR_IN_SECONDS);
            if (is_wp_error($data)) return ['available'=>false];
            $holidays = get_transient('dxb_bank_holidays');
            if ($holidays === false) {
                $response = wp_remote_get('https://www.gov.uk/bank-holidays.json', ['timeout'=>5]);
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) return ['available'=>false];
                $events = json_decode(wp_remote_retrieve_body($response), true)['england-and-wales']['events'] ?? [];
                if (!$events) return ['available'=>false];
                $holidays = array_column($events, 'date'); set_transient('dxb_bank_holidays',$holidays,DAY_IN_SECONDS);
            }
            $result = dxb_resolve_timetable($data, new DateTimeImmutable('now',new DateTimeZone('Europe/London')), $holidays);
            $result['source'] = 'https://tfl.gov.uk/bus/timetable/'.rawurlencode($route).'?fromId='.rawurlencode($request['stop']);
            return $result;
        }
    ]);
});
