<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Merge\{IniMergeStrategyTest, ListMergeStrategyTest, TextMergeStrategyTest};
use RuntimeException;

use function file_get_contents;
use function rtrim;
use function sprintf;
use function str_replace;

/**
 * Data provider for {@see IniMergeStrategyTest}, {@see ListMergeStrategyTest} and {@see TextMergeStrategyTest} test
 * cases.
 */
final class MergeStrategyProvider
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function iniCases(): iterable
    {
        $provider = self::fixture('ini/provider.txt');

        $cases = [
            'php-forge/scaffold (brace section, unset value)' => [
                $provider,
                self::fixture('ini/consumer-scaffold.txt'),
                self::fixture('ini/consumer-scaffold.expected.txt'),
            ],
            'consumer identical to provider' => [
                $provider,
                $provider,
                $provider,
            ],
            'yii2-extensions/app-inertia-react (five sections missing)' => [
                $provider,
                self::fixture('ini/consumer-app-inertia-react.txt'),
                self::fixture('ini/consumer-app-inertia-react.expected.txt'),
            ],
            'custom indent_size and custom section' => [
                $provider,
                self::fixture('ini/consumer-custom-sections.txt'),
                self::fixture('ini/consumer-custom-sections.expected.txt'),
            ],
        ];

        yield from $cases;
        yield from self::crlfVariants($cases);
        yield 'empty consumer' => [
            $provider,
            '',
            $provider,
        ];
        yield 'first preamble occurrence of a duplicated key wins' => [
            "a = 1\na = 2\n\n[*]\n",
            "[*]\n",
            "a = 1\n\n[*]\n",
        ];
        yield 'first section occurrence of a duplicated key wins' => [
            "[*]\na = 1\na = 2\n",
            "[*]\n",
            "[*]\na = 1\n",
        ];
        yield 'missing key lands in the first occurrence of a duplicated section' => [
            "[*]\na = 1\nb = 2\nc = 3\n",
            "[*]\na = 1\n\n[*.md]\nx = 1\n\n[*]\nb = 2\n",
            "[*]\na = 1\nc = 3\n\n[*.md]\nx = 1\n\n[*]\nb = 2\n",
        ];
        yield 'numeric key present in the consumer section is kept' => [
            "[numbers]\n2 = provider\n",
            "[numbers]\n1 = one\n\n[numbers]\n2 = consumer\n",
            "[numbers]\n1 = one\n\n[numbers]\n2 = consumer\n",
        ];
        yield 'comment lead travels with its appended section' => [
            "[*]\na = 1\n\n# Markdown\n[*.md]\nb = 2\n",
            "[*]\na = 1\n",
            "[*]\na = 1\n\n# Markdown\n[*.md]\nb = 2\n",
        ];
        yield 'comment on the first provider line leads the first section' => [
            "# c\n[*.md]\nb = 1\n",
            "[*]\n",
            "[*]\n\n# c\n[*.md]\nb = 1\n",
        ];
        yield 'indented comment counts as a lead line' => [
            "  # lead\n[*.md]\nb = 1\n",
            "[*]\n",
            "[*]\n\n  # lead\n[*.md]\nb = 1\n",
        ];
        yield 'lead of a middle section moves with it' => [
            "[a]\nx = 1\n# lead b\n[b]\ny = 1\n[c]\nz = 1\n",
            "root = true\n",
            "root = true\n\n[a]\nx = 1\n\n# lead b\n[b]\ny = 1\n\n[c]\nz = 1\n",
        ];
        yield 'comment right after a header leads the next section' => [
            "[a]\n# lead b\n[b]\ny = 1\n",
            "root = true\n",
            "root = true\n\n[a]\n\n# lead b\n[b]\ny = 1\n",
        ];
        yield 'section after a repeated provider section is still appended' => [
            "[*.md]\na = 1\n\n[*.md]\nb = 2\n\n[*.js]\nc = 3\n",
            "root = true\n",
            "root = true\n\n[*.md]\na = 1\nb = 2\n\n[*.js]\nc = 3\n",
        ];
        yield 'second provider occurrence adds only its new keys' => [
            "[*.md]\na = 1\n\n[*.md]\na = 9\nb = 2\n",
            "root = true\n",
            "root = true\n\n[*.md]\na = 1\nb = 2\n",
        ];
        yield 'comment holding an equals sign is no key' => [
            "[*]\n# a = 1\n",
            "[*]\n",
            "[*]\n",
        ];
        yield 'indented comment holding an equals sign is no key' => [
            "[*]\n  # a = 1\n",
            "[*]\n",
            "[*]\n",
        ];
        yield 'only key lines are inserted' => [
            "[*]\n; comment\nno-equals\n= x\na = 1\n",
            "[*]\n",
            "[*]\na = 1\n",
        ];
        yield 'key right after the header of a keyless consumer section' => [
            "[*]\na = 1\n",
            "[*]\n# note\n\n[*.md]\nb = 2\n",
            "[*]\na = 1\n# note\n\n[*.md]\nb = 2\n",
        ];
        yield 'key after the last consumer key of its section' => [
            "[*]\nc = 3\n",
            "[*]\na = 1\nb = 2\n",
            "[*]\na = 1\nb = 2\nc = 3\n",
        ];
        yield 'preamble key after the last consumer preamble key' => [
            "root = true\nfoo = bar\n\n[*]\n",
            "# c\nroot = false\n\n[*]\n",
            "# c\nroot = false\nfoo = bar\n\n[*]\n",
        ];
        yield 'preamble key at the top, separated from the first section' => [
            "root = true\n\n[*]\na = 1\n",
            "[*]\na = 1\n",
            "root = true\n\n[*]\na = 1\n",
        ];
        yield 'preamble key at the top of a keyless consumer preamble' => [
            "root = true\n",
            "# editorconfig.org\n\n[*]\n",
            "root = true\n# editorconfig.org\n\n[*]\n",
        ];
        yield 'inserted preamble key is trimmed' => [
            "  root = true\n\n[*]\n",
            "[*]\n",
            "root = true\n\n[*]\n",
        ];
        yield 'empty provider' => ['', "[*]\nb = 2\n", "[*]\nb = 2\n"];
        yield 'key differing only in case keeps the consumer value' => [
            "[*]\nindent_size = 4\n",
            "[*]\nINDENT_SIZE = 2\n",
            "[*]\nINDENT_SIZE = 2\n",
        ];
        yield 'key names are trimmed' => [
            "[*]\na=1\n",
            "[*]\na = 2\n",
            "[*]\na = 2\n",
        ];
        yield 'indented header is the same section' => [
            "[*.md]\nb = 2\n",
            "  [*.md]\nb = 2\n",
            "  [*.md]\nb = 2\n",
        ];
        yield 'no trailing newline on either side' => [
            "[*]\na = 1",
            "[*]\nb = 2",
            "[*]\nb = 2\na = 1",
        ];
        yield 'value ending in a bracket stays a key' => [
            "[*]\nlist = [a]\n",
            "[*]\n",
            "[*]\nlist = [a]\n",
        ];
        yield 'line starting with a bracket stays a key' => [
            "[*]\n[a]b = 1\n",
            "[*]\n",
            "[*]\n[a]b = 1\n",
        ];
        yield 'CR-only consumer' => [
            "[*]\na = 1\n",
            "[*]\rb = 2\r",
            "[*]\rb = 2\ra = 1\r",
        ];
        yield 'whitespace-only lines ending an appended section are dropped' => [
            "[a]\nx=1\n   \n[b]\ny=2\n",
            "[c]\nz=3\n",
            "[c]\nz=3\n\n[a]\nx=1\n\n[b]\ny=2\n",
        ];
        yield 'consumer unset value wins and a missing section is appended whole' => [
            "[*.md]\nindent_size = 2\ntrim_trailing_whitespace = false\n\n[*.js]\nindent_size = 2\n",
            "[*.md]\nindent_size = unset\n",
            "[*.md]\nindent_size = unset\ntrim_trailing_whitespace = false\n\n[*.js]\nindent_size = 2\n",
        ];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function listCases(): iterable
    {
        $v1 = self::fixture('list/provider-v1.txt');
        $v2 = self::fixture('list/provider-v2.txt');

        $cases = [
            'php-forge/scaffold (custom headers, negation) with v1' => [
                $v1,
                self::fixture('list/consumer-scaffold.txt'),
                self::fixture('list/consumer-scaffold.v1.expected.txt'),
            ],
            'php-forge/scaffold (custom headers, negation) with v2' => [
                $v2,
                self::fixture('list/consumer-scaffold.txt'),
                self::fixture('list/consumer-scaffold.v2.expected.txt'),
            ],
            'consumer identical to v1' => [
                $v1,
                $v1,
                $v1,
            ],
            'consumer identical to v1 with v2' => [
                $v2,
                $v1,
                $v2,
            ],
            'every entry present in a different order' => [
                $v1,
                self::fixture('list/consumer-reordered.txt'),
                self::fixture('list/consumer-reordered.txt'),
            ],
        ];

        yield from $cases;
        yield from self::crlfVariants($cases);
        yield 'no trailing newline with v1' => [
            $v1,
            rtrim($v1, "\n"),
            rtrim($v1, "\n"),
        ];
        yield 'no trailing newline with v2' => [
            $v2,
            rtrim($v1, "\n"),
            $v2,
        ];
        yield 'empty consumer' => [
            $v1,
            '',
            $v1,
        ];
        // Known limitation: the appended '*.log' follows the consumer '!keep.log', and since the last matching rule
        // wins, 'keep.log' becomes ignored again. The merge does not reorder consumer rules.
        yield 'entry appended after a consumer negation' => [
            "# logs\n*.log\n",
            "build\n!keep.log\n",
            "build\n!keep.log\n\n# logs\n*.log\n",
        ];
        yield 'group without comment appended as entries only' => [
            "a\n\nb\nc\n",
            "a\n",
            "a\n\nb\nc\n",
        ];
        yield 'every uncommented group appended' => [
            "a\n\nb\n",
            "x\n",
            "x\n\na\n\nb\n",
        ];
        yield 'entry repeated in the provider added once' => [
            "# one\nx\n\n# two\nx\ny\n",
            "z\n",
            "z\n\n# one\nx\n\n# two\ny\n",
        ];
        yield 'entry after the matching comment header without entries' => [
            "# cache\n.cache\n",
            "# cache\n# (if present)\n\nother\n",
            "# cache\n# (if present)\n.cache\n\nother\n",
        ];
        yield 'entry after the entries below a multi-line comment' => [
            "# vendor\nvendor\ncomposer.lock\n",
            "# vendor\n# more\nvendor\n",
            "# vendor\n# more\nvendor\ncomposer.lock\n",
        ];
        yield 'entry stays inside the matching group' => [
            "# tools\n.idea\n.vscode\n",
            "# tools\n.idea\n# other\nfoo\n",
            "# tools\n.idea\n.vscode\n# other\nfoo\n",
        ];
        yield 'comment after entries starts a new provider group' => [
            "# a\nx\nnew-x\n# b\ny\n",
            "# a\nx\n\n# b\ny\n",
            "# a\nx\nnew-x\n\n# b\ny\n",
        ];
        yield 'CR-only consumer' => [
            "# b\nb\n",
            "a\r",
            "a\r\r# b\rb\r",
        ];
        yield 'consumer entry with surrounding spaces is present' => [
            "x\n",
            " x \n",
            " x \n",
        ];
        yield 'provider entry with surrounding spaces is trimmed' => [
            " x \n",
            "x\n",
            "x\n",
        ];
        yield 'provider header with trailing spaces matches the consumer header' => [
            "# cache  \n.cache\n",
            "# cache\nother\n",
            "# cache\nother\n.cache\n",
        ];
        yield 'consumer header with trailing spaces matches the provider header' => [
            "# cache\n.cache\n",
            "# cache  \nother\n",
            "# cache  \nother\n.cache\n",
        ];
        yield 'whitespace-only consumer line ends the matching group' => [
            "# cache\n.cache\n",
            "# cache\nfoo\n  \nbar\n",
            "# cache\nfoo\n.cache\n  \nbar\n",
        ];
        yield 'consecutive provider comments head one group' => [
            "# a\n# b\nx\n",
            "y\n",
            "y\n\n# a\n# b\nx\n",
        ];
        yield 'differing comments alone change nothing' => [
            "# Local AI agent config (if present)\n.claude\n",
            "# AI agents\n.claude\n",
            "# AI agents\n.claude\n",
        ];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function textCases(): iterable
    {
        $provider = self::fixture('text/provider.txt');

        $cases = [
            'env-like file' => [
                $provider,
                self::fixture('text/consumer.txt'),
                self::fixture('text/consumer.expected.txt'),
            ],
        ];

        yield from $cases;
        yield from self::crlfVariants($cases);
        yield 'empty consumer' => [
            $provider,
            '',
            $provider,
        ];
        yield 'existing trailing blank line serves as the separator' => [
            "b\n",
            "a\n\n",
            "a\n\nb\n",
        ];
        yield 'trailing whitespace is ignored' => [
            "alpha  \n",
            "alpha\n",
            "alpha\n",
        ];
        yield 'consumer trailing whitespace is ignored' => [
            "alpha\n",
            "alpha  \n",
            "alpha  \n",
        ];
        yield 'leading whitespace is significant' => [
            "  alpha\n",
            "alpha\n",
            "alpha\n\n  alpha\n",
        ];
        yield 'no trailing newline on either side' => [
            'b',
            'a',
            "a\n\nb",
        ];
        yield 'blank provider lines are not content' => [
            "a\n\n\nb\n",
            "a\n",
            "a\n\nb\n",
        ];
        yield 'consumer without trailing newline is terminated' => [
            "b\n",
            'a',
            "a\n\nb\n",
        ];
        yield 'shared line terminated before the appended block' => [
            "alpha\nbeta\n",
            'alpha',
            "alpha\n\nbeta\n",
        ];
        yield 'only missing lines appended' => [
            "alpha\nbeta\ngamma\n",
            "alpha\nbeta\n",
            "alpha\nbeta\n\ngamma\n",
        ];
        yield 'missing line appended' => [
            "alpha\nbeta\n",
            "alpha\n",
            "alpha\n\nbeta\n",
        ];
        yield 'consumer additions kept' => [
            "alpha\nbeta\n",
            "alpha\nproject-specific-line\n",
            "alpha\nproject-specific-line\n\nbeta\n",
        ];
        yield 'CR-only consumer' => [
            "alpha\nbeta\n",
            "alpha\r",
            "alpha\r\rbeta\r",
        ];
        yield 'mixed line endings kept, appended lines use the dominant one' => [
            "alpha\ngamma\nbeta\n",
            "alpha\nbeta\r\n",
            "alpha\nbeta\r\n\r\ngamma\r\n",
        ];
        yield 'empty provider' => [
            '',
            "alpha\n",
            "alpha\n",
        ];
        yield 'consumer differing only by the final newline' => [
            "alpha\n",
            'alpha',
            'alpha',
        ];
        yield 'provider ending with a blank line' => [
            "alpha\n\n",
            '',
            "alpha\n\n",
        ];
        yield 'CRLF consumer holding every provider line' => [
            "alpha\nbeta\n",
            "alpha\r\nbeta\r\n",
            "alpha\r\nbeta\r\n",
        ];
        yield 'consumer holding every provider line and more' => [
            "alpha\nbeta\n",
            "alpha\nbeta\ngamma\n",
            "alpha\nbeta\ngamma\n",
        ];
        yield 'provider lines in a different order' => [
            "alpha\nbeta\n",
            "beta\nalpha\n",
            "beta\nalpha\n",
        ];
    }

    /**
     * Returns the cases with consumer and expected output converted to CRLF; the provider stays LF.
     *
     * @param array<string, array{string, string, string}> $cases Cases to convert.
     *
     * @return iterable<string, array{string, string, string}> Converted cases keyed with a ` (CRLF)` suffix.
     */
    private static function crlfVariants(array $cases): iterable
    {
        foreach ($cases as $name => [$provider, $consumer, $expected]) {
            yield "{$name} (CRLF)" => [
                $provider,
                str_replace("\n", "\r\n", $consumer),
                str_replace("\n", "\r\n", $expected),
            ];
        }
    }

    /**
     * Reads a fixture file from `tests/Fixtures/merge`.
     *
     * @param string $path Path relative to `tests/Fixtures/merge`.
     *
     * @throws RuntimeException if the fixture cannot be read.
     *
     * @return string Fixture content.
     */
    private static function fixture(string $path): string
    {
        $content = file_get_contents(__DIR__ . '/../Fixtures/merge/' . $path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read merge fixture "%s".', $path));
        }

        return $content;
    }
}
