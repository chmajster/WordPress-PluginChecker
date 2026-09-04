<?php

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

final class Plan_Version
{
    public static function normalize_tag(string $tag, bool $allow_prerelease = false): ?string
    {
        $tag = trim($tag);
        if (str_starts_with($tag, 'v')) {
            $tag = substr($tag, 1);
        }

        $pattern = $allow_prerelease
            ? '/^\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.?\d*)?$/i'
            : '/^\d+\.\d+\.\d+$/';

        return preg_match($pattern, $tag) === 1 ? $tag : null;
    }

    public static function is_update_available(string $local, string $remote): bool
    {
        $local = self::normalize_tag($local, true) ?? $local;
        $remote = self::normalize_tag($remote, true) ?? $remote;
        return version_compare($remote, $local, '>');
    }

    /** @return array<string, mixed>|null */
    public static function latest_release(array $releases, string $asset_name, string $channel = 'stable'): ?array
    {
        $candidates = [];
        $allow_prerelease = $channel === 'beta';

        foreach ($releases as $release) {
            if (!empty($release['draft'])) {
                continue;
            }
            if (!$allow_prerelease && !empty($release['prerelease'])) {
                continue;
            }

            $version = self::normalize_tag((string)($release['tag_name'] ?? ''), $allow_prerelease);
            if ($version === null) {
                continue;
            }

            $release['_normalized_version'] = $version;
            $candidates[] = $release;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn(array $a, array $b): int => version_compare(
            (string)$b['_normalized_version'],
            (string)$a['_normalized_version']
        ));

        $release = $candidates[0];
        $release['_asset'] = null;
        foreach ((array)($release['assets'] ?? []) as $asset) {
            if ((string)($asset['name'] ?? '') === $asset_name) {
                $release['_asset'] = $asset;
                break;
            }
        }
        return $release;
    }

    public static function latest_stable_release(array $releases, string $asset_name): ?array
    {
        return self::latest_release($releases, $asset_name, 'stable');
    }
}
