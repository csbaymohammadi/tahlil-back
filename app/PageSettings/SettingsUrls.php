<?php

namespace App\PageSettings;

class SettingsUrls
{
    public function destination(string $value, bool $absoluteOnly = false): ?string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 2048 || preg_match('/[\\\\\x00-\x20\x7f]/u', $value)) {
            return null;
        }
        $decoded = $value;
        do {
            $previous = $decoded;
            $decoded = rawurldecode($decoded);
            if (preg_match('~[\\\\\x00-\x20\x7f]~u', $decoded) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~u', parse_url($decoded, PHP_URL_PATH) ?: '')) {
                return null;
            }
        } while ($previous !== $decoded);
        if (! $absoluteOnly && preg_match('/^#[A-Za-z0-9_.:-]+$/D', $value)) {
            return $value;
        }
        if (! $absoluteOnly && str_starts_with($value, '/') && ! str_starts_with($decoded, '//')) {
            return $value;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) && $parts['port'] !== ($scheme === 'https' ? 443 : 80) ? ':'.$parts['port'] : '';

        return $scheme.'://'.strtolower($parts['host']).$port.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public function linkedin(string $value): ?string
    {
        if ($value === '') {
            return '';
        }
        $url = $this->destination($value, true);
        $host = $url === null ? '' : parse_url($url, PHP_URL_HOST);

        return $host === 'linkedin.com' || str_ends_with($host, '.linkedin.com') ? $url : null;
    }
}
