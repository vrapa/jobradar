<?php

declare(strict_types=1);

namespace App\Opportunity;

final class UrlNormalizer
{
    private const TRACKING_PARAMETERS = [
        'fbclid',
        'gclid',
        'mc_cid',
        'mc_eid',
        'ref',
    ];

    public function normalize(string $url): string
    {
        $url = trim($url);
        if (mb_strlen($url) > 2048) {
            throw new \InvalidArgumentException('URL smí mít nejvýše 2048 znaků.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('URL nabídky není platná absolutní adresa.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('URL nabídky nesmí obsahovat přihlašovací údaje.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('URL nabídky musí používat HTTP nebo HTTPS.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port']) && !(($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443))
            ? ':' . $parts['port']
            : '';
        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : preg_replace('~/+~', '/', $path);
        if ($path === null) {
            throw new \InvalidArgumentException('URL nabídky obsahuje neplatnou cestu.');
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = $this->normalizeQuery($parts['query'] ?? null);

        return sprintf('%s://%s%s%s%s', $scheme, $host, $port, $path, $query === '' ? '' : '?' . $query);
    }

    private function normalizeQuery(?string $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }
        parse_str($query, $parameters);
        foreach (array_keys($parameters) as $name) {
            if (str_starts_with(strtolower((string) $name), 'utm_') || in_array(strtolower((string) $name), self::TRACKING_PARAMETERS, true)) {
                unset($parameters[$name]);
            }
        }
        ksort($parameters, SORT_STRING);

        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
