<?php

namespace App\Services;

final class SqlInjectionDetector
{
    /** @var array<string, array{pattern:string,severity:string,confidence:float}> */
    private const RULES = [
        'SQLI_TIME_BASED' => ['pattern' => '/\b(?:sleep|benchmark|pg_sleep)\s*\(|\bwaitfor\s+delay\b/i', 'severity' => 'High', 'confidence' => 0.98],
        'SQLI_UNION_SELECT' => ['pattern' => '/\bunion\s+(?:all\s+)?select\b/i', 'severity' => 'High', 'confidence' => 0.98],
        'SQLI_SCHEMA_PROBE' => ['pattern' => '/\b(?:select|from|join)\b.{0,100}\b(?:information_schema|pg_catalog|sqlite_master|sys\.tables)\b|\b(?:information_schema|pg_catalog|sqlite_master|sys\.tables)\b.{0,100}\b(?:select|from|join)\b/i', 'severity' => 'High', 'confidence' => 0.96],
        'SQLI_STACKED_QUERY' => ['pattern' => '/;\s*(?:select|insert|update|delete|drop|alter|create|truncate|exec(?:ute)?)\b/i', 'severity' => 'High', 'confidence' => 0.95],
        'SQLI_TAUTOLOGY' => ['pattern' => '/(?:\'|"|`|%27|%22)\s*(?:or|and)\s+(?:\d+\s*=\s*\d+|(?:\'[^\']*\'|"[^"]*")\s*=\s*(?:\'[^\']*\'|"[^"]*"))/i', 'severity' => 'High', 'confidence' => 0.94],
        'SQLI_COMMENT_OPERATOR' => ['pattern' => '/(?:\b(?:select|union|where|or|and|drop|update|delete|insert)\b.{0,80}(?:--|#|\/\*)|(?:--|#|\/\*).{0,80}\b(?:select|union|where|or|and|drop|update|delete|insert)\b)/i', 'severity' => 'High', 'confidence' => 0.90],
    ];

    /** @return array{rule:string,severity:string,confidence:float}|null */
    public function detect(string $value): ?array
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') {
            return null;
        }

        foreach (self::RULES as $rule => $definition) {
            if (preg_match($definition['pattern'], $normalized) === 1) {
                return ['rule' => $rule, 'severity' => $definition['severity'], 'confidence' => $definition['confidence']];
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_substr($value, 0, 8192);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $decoded = rawurldecode($value);
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return mb_strtolower(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $value);
    }
}
