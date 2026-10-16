<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/**
 * Pure helpers for `make divi-currency`: what changed between two copies of the Divi theme, in the terms this project cares about
 * (new and removed modules, changed module definitions, new server code, what the changelog says). Reads two unpacked theme folders;
 * decides nothing and promotes nothing.
 */
final class DiviCurrency
{
    private const MODULES_DIR = 'includes/builder-5/visual-builder/packages/module-library/src/components';
    private const SERVER_DIR  = 'includes/builder-5/server';

    public static function version(string $themeDir): ?string
    {
        $css = @file_get_contents($themeDir . '/style.css');

        return is_string($css) && preg_match('/^\s*Version:\s*(\S+)/mi', $css, $m) ? $m[1] : null;
    }

    /**
     * The changelog entries newer than $oldVersion (those above its own entry); all of them when it is null or not found.
     *
     * @return list<array{version:string,date:string,lines:list<string>}>
     */
    public static function changelogSince(string $changelog, ?string $oldVersion): array
    {
        $entries = [];
        $cur     = null;
        foreach (preg_split('/\R/', $changelog) ?: [] as $line) {
            if (preg_match('/^version\s+(\S+)\s*\(\s*updated\s+([^)]*?)\s*\)/i', $line, $m)) {
                if (null !== $cur) {
                    $entries[] = $cur;
                }
                $cur = ['version' => $m[1], 'date' => $m[2], 'lines' => []];
            } elseif (null !== $cur && str_starts_with(ltrim($line), '- ')) {
                $cur['lines'][] = trim(substr(ltrim($line), 2));
            }
        }
        if (null !== $cur) {
            $entries[] = $cur;
        }
        if (null === $oldVersion) {
            return $entries;
        }
        $out = [];
        foreach ($entries as $e) {
            if ($e['version'] === $oldVersion) {
                return $out;
            }
            $out[] = $e;
        }

        return $entries; // the old version is not in the log: show everything rather than nothing
    }

    /** @return list<string> the module folder names under the module library (for example "blurb", "global-slot") */
    public static function moduleNames(string $themeDir): array
    {
        $dirs = glob($themeDir . '/' . self::MODULES_DIR . '/*', GLOB_ONLYDIR) ?: [];
        $out  = array_map('basename', $dirs);
        sort($out);

        return $out;
    }

    /**
     * @return array{added:list<string>,removed:list<string>,changed:list<string>} relative paths of what differs; module.json files and the
     *         server PHP only (translations, compiled builds and minified assets change on every release and say nothing)
     */
    public static function diff(string $oldDir, string $newDir): array
    {
        $old = self::hashes($oldDir);
        $new = self::hashes($newDir);
        $out = ['added' => [], 'removed' => [], 'changed' => []];
        foreach ($new as $path => $hash) {
            if (!isset($old[$path])) {
                $out['added'][] = $path;
            } elseif ($old[$path] !== $hash) {
                $out['changed'][] = $path;
            }
        }
        foreach ($old as $path => $_) {
            if (!isset($new[$path])) {
                $out['removed'][] = $path;
            }
        }
        sort($out['added']);
        sort($out['removed']);
        sort($out['changed']);

        return $out;
    }

    /** @return array<string,string> relative path => md5 */
    private static function hashes(string $dir): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = ltrim(substr($file->getPathname(), strlen($dir)), '/');
            $isModule = str_starts_with($rel, self::MODULES_DIR . '/') && str_ends_with($rel, '.json');
            $isServer = str_starts_with($rel, self::SERVER_DIR . '/') && str_ends_with($rel, '.php');
            if ($isModule || $isServer) {
                $out[$rel] = (string) md5_file($file->getPathname());
            }
        }

        return $out;
    }

    /**
     * @param array{added:list<string>,removed:list<string>,changed:list<string>} $diff
     * @param list<array{version:string,date:string,lines:list<string>}>           $changelog
     * @param list<string>                                                           $newModules  module folder names only in the new theme
     * @param list<string>                                                           $goneModules module folder names only in the old theme
     */
    public static function report(?string $oldVersion, string $newVersion, array $diff, array $changelog, array $newModules, array $goneModules): string
    {
        $md  = "# Divi {$newVersion}: what changed" . (null === $oldVersion ? '' : " since {$oldVersion}") . "\n\n";
        $md .= "Generated by `make divi-currency` (`scripts/divi-currency.sh`). It reports; it never promotes a module, edits a rule or saves anything: every new block type still needs a real export and a live render before the validator accepts it.\n\n";

        $md .= "## Modules\n\n";
        $md .= [] === $newModules ? "- No new module folders.\n" : '- **New:** ' . implode(', ', array_map(static fn (string $m): string => "`divi/{$m}`", $newModules)) . "\n";
        $md .= [] === $goneModules ? "- None removed.\n" : '- **Removed:** ' . implode(', ', array_map(static fn (string $m): string => "`divi/{$m}`", $goneModules)) . "\n";
        $defs = array_values(array_filter($diff['changed'], static fn (string $p): bool => str_ends_with($p, 'module.json')));
        $md .= "- **Module definitions changed (" . count($defs) . "):** " . ([] === $defs ? 'none' : implode(', ', array_map(static fn (string $p): string => '`' . basename(dirname($p)) . '`', $defs))) . "\n";

        $md .= "\n## Server code\n\n";
        $serverAdded = array_values(array_filter($diff['added'], static fn (string $p): bool => str_ends_with($p, '.php')));
        $serverGone  = array_values(array_filter($diff['removed'], static fn (string $p): bool => str_ends_with($p, '.php')));
        $serverCh    = array_values(array_filter($diff['changed'], static fn (string $p): bool => str_ends_with($p, '.php')));
        $md .= '- **New files (' . count($serverAdded) . '):** ' . ([] === $serverAdded ? 'none' : implode(', ', array_map(static fn (string $p): string => '`' . basename($p) . '`', $serverAdded))) . "\n";
        $md .= '- **Removed files (' . count($serverGone) . '):** ' . ([] === $serverGone ? 'none' : implode(', ', array_map(static fn (string $p): string => '`' . basename($p) . '`', $serverGone))) . "\n";
        $md .= '- **Changed files:** ' . count($serverCh) . "\n";
        $watch = array_values(array_filter($serverCh, static fn (string $p): bool => 1 === preg_match('#/(GlobalData|GlobalLayout|GlobalPreset|Migration|BlockParser|ModuleRegistration)/#', $p) || str_contains($p, 'GlobalData') || str_contains($p, 'Migration/')));
        if ([] !== $watch) {
            $md .= '- **Worth reading (they touch what the Pro add-on or the validator depend on):** ' . implode(', ', array_map(static fn (string $p): string => '`' . substr($p, strlen(self::SERVER_DIR) + 1) . '`', array_slice($watch, 0, 30))) . "\n";
        }

        $md .= "\n## Changelog\n\n";
        if ([] === $changelog) {
            $md .= "No newer changelog entries.\n";
        }
        foreach ($changelog as $e) {
            $md .= "### {$e['version']} ({$e['date']})\n\n";
            foreach ($e['lines'] as $l) {
                $md .= "- {$l}\n";
            }
            $md .= "\n";
        }

        return $md;
    }
}
