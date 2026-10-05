<?php

use App\Services\SqlInjectionDetector;

it('detects contextual sql injection families including encoded payloads', function (string $payload, string $rule): void {
    expect(app(SqlInjectionDetector::class)->detect($payload))->toMatchArray(['rule' => $rule, 'severity' => 'High']);
})->with([
    ["' OR 1=1 --", 'SQLI_TAUTOLOGY'],
    ['2 UNION ALL SELECT password FROM users', 'SQLI_UNION_SELECT'],
    ['1; DROP TABLE reservations', 'SQLI_STACKED_QUERY'],
    ['SELECT table_name FROM information_schema.tables', 'SQLI_SCHEMA_PROBE'],
    ['1 AND SLEEP(5)', 'SQLI_TIME_BASED'],
    ['%2527%2520OR%25201%253D1%2520--', 'SQLI_TAUTOLOGY'],
]);

it('does not flag normal hotel search and prose containing isolated sql words', function (string $value): void {
    expect(app(SqlInjectionDetector::class)->detect($value))->toBeNull();
})->with([
    'Union Square hotel',
    'Select a room and update your reservation',
    'Breakfast #1 package',
    'King room -- ocean view',
    "O'Reilly family booking",
]);
