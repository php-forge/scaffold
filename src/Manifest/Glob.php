<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Manifest;

use LogicException;
use PHPForge\Scaffold\Exception\Message;

use function array_map;
use function explode;
use function implode;
use function in_array;
use function preg_last_error_msg;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Matches forward-slash paths against manifest glob patterns: `*` and `?` within one path segment, `**` across
 * segments, single-level `{a,b}` alternatives, and byte-exact literals for everything else.
 */
final class Glob
{
    /**
     * Returns the value of the first key of `$map` that matches `$path`; an exact key wins over globs, which match in
     * declaration order.
     *
     * @template TValue
     *
     * @param array<array-key, TValue> $map Values keyed by glob pattern; integer-like patterns are integer keys.
     * @param string $path Relative path with forward slashes.
     *
     * @return TValue|null Value of the matching pattern, or `null` when no pattern matches.
     */
    public static function lookup(array $map, string $path): mixed
    {
        if (isset($map[$path])) {
            return $map[$path];
        }

        foreach ($map as $pattern => $value) {
            // PHP turns integer-like patterns ("123") into integer array keys.
            if (self::matches((string) $pattern, $path)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Returns whether `$pattern` matches the whole of `$path`.
     *
     * @param string $pattern Glob pattern to match against.
     * @param string $path Relative path with forward slashes; this method does not convert backslashes.
     *
     * @return bool `true` when the pattern matches the full path, `false` otherwise.
     */
    public static function matches(string $pattern, string $path): bool
    {
        return preg_match(self::toRegex($pattern), $path) === 1;
    }

    /**
     * Returns whether any of `$patterns` matches the whole of `$path`.
     *
     * @param list<string> $patterns Glob patterns.
     * @param string $path Relative path with forward slashes.
     *
     * @return bool `true` when at least one pattern matches the path, `false` otherwise.
     */
    public static function matchesAny(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether a `<dir>/**` pattern of `$patterns` excludes every descendant of `$directory`, so a walk can skip
     * it.
     *
     * @param list<string> $patterns Glob patterns.
     * @param string $directory Relative directory path with forward slashes.
     *
     * @return bool `true` when `$patterns` holds `<directory>/**`, `false` otherwise.
     */
    public static function prunes(array $patterns, string $directory): bool
    {
        return in_array("{$directory}/**", $patterns, true);
    }

    /**
     * Converts a glob pattern into an anchored PCRE regex, for example `#^config/[^/]*\.php$#` for `config/*.php`.
     *
     * @param string $pattern Glob pattern to convert.
     *
     * @return string PCRE regex with the `#` delimiter, anchored to match the full string.
     */
    public static function toRegex(string $pattern): string
    {
        return '#^' . self::convert($pattern) . '$#';
    }

    /**
     * Converts a glob pattern, or one brace alternative, into an unanchored PCRE fragment for the `#` delimiter.
     *
     * @param string $pattern Glob pattern or single brace alternative to convert.
     *
     * @return string Unanchored PCRE fragment with literals quoted for the `#` delimiter.
     */
    private static function convert(string $pattern): string
    {
        // '**/' expands to "any number of directories including zero" so '**/foo' matches both 'foo' and 'a/b/foo'.
        return preg_replace_callback(
            '#\*\*/|\*\*|\*|\?|\{[^{}]*,[^{}]*\}|\{|[^*?{]+#',
            static fn(array $match): string => match (true) {
                $match[0] === '**/' => '(?:.*/)?',
                $match[0] === '**' => '.*',
                $match[0] === '*' => '[^/]*',
                $match[0] === '?' => '[^/]',
                str_starts_with($match[0], '{') && str_ends_with($match[0], '}') => '(?:' . implode(
                    '|',
                    array_map(self::convert(...), explode(',', substr($match[0], 1, -1))),
                ) . ')',
                default => preg_quote($match[0], '#'),
            },
            $pattern,
        ) ?? throw new LogicException(Message::REGEX_FAILED->getMessage(preg_last_error_msg()));
    }
}
