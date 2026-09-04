<?php

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

final class Plan_Version
{
    public static function normalize_tag(string $tag): ?string
    {
        $tag = trim($tag);
        if (str_starts_with($tag, 'v')) {
            $tag = substr($tag, 1);
        }

        return preg_match('/^\d+\.\d+\.\d+$/', $tag) === 1 ? $tag : null;
    }

    public static function is_update_available(string $local, string $remote): bool
    {
        $local = self::normalize_tag($local) ?? $local;
        $remote = self::normalize_tag($remote) ?? $remote;

        return version_compare($remote, $local, '>');
    }

    /**
     * @param array<int, array<string, mixed>> $releases
     * @return array<string, mixed>|null
     */
    public static function latest_stable_release(array $releases, string $asset_name): ?array
    {
        $stable = [];

        foreach ($releases as $release) {
            if (!empty($release['draft']) || !empty($release['prerelease'])) {
                continue;
            }

            $version = self::normalize_tag((string)($release['tag_name'] ?? ''));
            if ($version === null) {
                continue;
            }

            $release['_normalized_version'] = $version;
            $stable[] = $release;
        }

        if ($stable === []) {
            return null;
        }

        usort(
            $stable,
            static fn(array $a, array $b): int => version_compare(
                (string)$b['_normalized_version'],
                (string)$a['_normalized_version']
            )
        );

        $release = $stable[0];
        $release['_asset'] = null;

        foreach ((array)($release['assets'] ?? []) as $asset) {
            if ((string)($asset['name'] ?? '') === $asset_name) {
                $release['_asset'] = $asset;
                break;
            }
        }

        return $release;
    }
}
