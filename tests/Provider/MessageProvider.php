<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Exception\Message;

/**
 * Data provider for {@see \PHPForge\Scaffold\Tests\Exception\MessageTest} test cases.
 */
final class MessageProvider
{
    /**
     * @phpstan-return array<string, array{Message, list<int|string>, string}>
     */
    public static function cases(): array
    {
        return [
            'CONFIG_MANIFEST_INVALID_JSON' => [
                Message::CONFIG_MANIFEST_INVALID_JSON,
                ['/project/composer.json', 'Syntax error'],
                'Project manifest "/project/composer.json" is not valid JSON: Syntax error.',
            ],
            'CONFIG_MANIFEST_NOT_FOUND' => [
                Message::CONFIG_MANIFEST_NOT_FOUND,
                ['/project/composer.json'],
                'Project manifest "/project/composer.json" not found.',
            ],
            'CONFIG_MANIFEST_NOT_OBJECT' => [
                Message::CONFIG_MANIFEST_NOT_OBJECT,
                ['/project/composer.json'],
                'Project manifest "/project/composer.json" does not contain a JSON object.',
            ],
            'CONFIG_MANIFEST_UNREADABLE' => [
                Message::CONFIG_MANIFEST_UNREADABLE,
                ['/project/composer.json'],
                'Could not read project manifest "/project/composer.json".',
            ],
            'FILESYSTEM_FAILED' => [
                Message::FILESYSTEM_FAILED,
                ['Could not read "/project/a.txt".', 'file_get_contents(/project/a.txt): Failed to open stream'],
                'Could not read "/project/a.txt". Reason: file_get_contents(/project/a.txt): Failed to open stream.',
            ],
            'LOCK_CONFLICT_MARKERS' => [
                Message::LOCK_CONFLICT_MARKERS,
                ['/project/scaffold-lock.json', 'Syntax error'],
                'Lock file "/project/scaffold-lock.json" contains Git conflict markers and is not valid JSON '
                . '(Syntax error). Resolve the merge conflict and run the command again.',
            ],
            'LOCK_ENCODE_FAILED' => [
                Message::LOCK_ENCODE_FAILED,
                ['/project/scaffold-lock.json', 'Malformed UTF-8 characters, possibly incorrectly encoded'],
                'Could not encode lock file "/project/scaffold-lock.json": Malformed UTF-8 characters, possibly '
                . 'incorrectly encoded.',
            ],
            'LOCK_HASH_FAILED' => [
                Message::LOCK_HASH_FAILED,
                ['/project/a.txt'],
                'Could not hash file "/project/a.txt".',
            ],
            'LOCK_INVALID_JSON' => [
                Message::LOCK_INVALID_JSON,
                ['/project/scaffold-lock.json', 'Syntax error'],
                'Lock file "/project/scaffold-lock.json" is not valid JSON: Syntax error.',
            ],
            'LOCK_NOT_OBJECT' => [
                Message::LOCK_NOT_OBJECT,
                ['/project/scaffold-lock.json'],
                'Lock file "/project/scaffold-lock.json" does not contain a valid JSON object.',
            ],
            'LOCK_MODE_UNKNOWN' => [
                Message::LOCK_MODE_UNKNOWN,
                ['copy'],
                'Unknown mode "copy" in scaffold-lock.json.',
            ],
            'LOCK_UNREADABLE' => [
                Message::LOCK_UNREADABLE,
                ['/project/scaffold-lock.json'],
                'Could not read lock file "/project/scaffold-lock.json".',
            ],
            'LOCK_UNWRITABLE' => [
                Message::LOCK_UNWRITABLE,
                ['/project/scaffold-lock.json'],
                'Could not write lock file "/project/scaffold-lock.json".',
            ],
            'MANIFEST_COPY_EMPTY' => [
                Message::MANIFEST_COPY_EMPTY,
                [],
                'Manifest "copy" must declare at least one path.',
            ],
            'MANIFEST_COPY_ENTRY_INVALID' => [
                Message::MANIFEST_COPY_ENTRY_INVALID,
                [],
                'Manifest "copy" entries must be either a string or an object with "from" and "to" keys.',
            ],
            'MANIFEST_COPY_ENTRY_NOT_FOUND' => [
                Message::MANIFEST_COPY_ENTRY_NOT_FOUND,
                ['missing-dir', '/vendor/p/a'],
                'Scaffold copy entry "missing-dir" does not exist under provider root "/vendor/p/a".',
            ],
            'MANIFEST_COPY_MISSING' => [
                Message::MANIFEST_COPY_MISSING,
                [],
                'Manifest is missing required key "copy" or it is not an array.',
            ],
            'MANIFEST_COPY_NOT_LIST' => [
                Message::MANIFEST_COPY_NOT_LIST,
                [],
                'Manifest "copy" must be a sequential list of entries, not an associative object.',
            ],
            'MANIFEST_ENTRY_ABSOLUTE' => [
                Message::MANIFEST_ENTRY_ABSOLUTE,
                ['copy', '/etc'],
                'Manifest "copy" entry "/etc" must be a relative path.',
            ],
            'MANIFEST_ENTRY_EMPTY' => [
                Message::MANIFEST_ENTRY_EMPTY,
                ['exclude'],
                'Manifest "exclude" entries must be non-empty strings.',
            ],
            'MANIFEST_ENTRY_TRAVERSAL' => [
                Message::MANIFEST_ENTRY_TRAVERSAL,
                ['modes', '../escape'],
                'Manifest "modes" entry "../escape" must not contain path traversal segments.',
            ],
            'MANIFEST_EXCLUDE_NOT_ARRAY' => [
                Message::MANIFEST_EXCLUDE_NOT_ARRAY,
                [],
                'Manifest "exclude" must be an array when present.',
            ],
            'MANIFEST_KEY_UNKNOWN' => [
                Message::MANIFEST_KEY_UNKNOWN,
                ['mdoes', 'copy, exclude, merge, modes'],
                'Manifest contains unknown key "mdoes". Allowed keys: copy, exclude, merge, modes.',
            ],
            'MANIFEST_MAP_NOT_OBJECT' => [
                Message::MANIFEST_MAP_NOT_OBJECT,
                ['merge'],
                'Manifest "merge" must be an object when present.',
            ],
            'MANIFEST_MAP_VALUE_INVALID' => [
                Message::MANIFEST_MAP_VALUE_INVALID,
                ['modes', 'config/*.php', 'mode', 'copy', 'append, prepend, preserve, replace'],
                'Manifest "modes" value for pattern "config/*.php" has invalid mode "copy". Allowed: append, prepend, '
                . 'preserve, replace.',
            ],
            'MANIFEST_MAP_VALUE_NOT_STRING' => [
                Message::MANIFEST_MAP_VALUE_NOT_STRING,
                ['merge', '.gitignore'],
                'Manifest "merge" value for pattern ".gitignore" must be a string.',
            ],
            'MANIFEST_PROVIDER_FILE_NOT_FOUND' => [
                Message::MANIFEST_PROVIDER_FILE_NOT_FOUND,
                ['p/a', 'composer.json', '/vendor/p/a/composer.json'],
                'Provider "p/a": composer.json not found at "/vendor/p/a/composer.json".',
            ],
            'MANIFEST_PROVIDER_FILE_NOT_OBJECT' => [
                Message::MANIFEST_PROVIDER_FILE_NOT_OBJECT,
                ['p/a', 'manifest file', '/vendor/p/a/scaffold.json'],
                'Provider "p/a": manifest file "/vendor/p/a/scaffold.json" must decode to an object.',
            ],
            'MANIFEST_PROVIDER_FILE_UNREADABLE' => [
                Message::MANIFEST_PROVIDER_FILE_UNREADABLE,
                ['p/a', 'manifest file', '/vendor/p/a/scaffold.json'],
                'Provider "p/a": could not read manifest file "/vendor/p/a/scaffold.json".',
            ],
            'MANIFEST_REFERENCE_EMPTY' => [
                Message::MANIFEST_REFERENCE_EMPTY,
                ['p/a'],
                'Provider "p/a": "manifest" must be a non-empty string.',
            ],
            'MANIFEST_REFERENCE_NOT_RELATIVE' => [
                Message::MANIFEST_REFERENCE_NOT_RELATIVE,
                ['p/a'],
                'Provider "p/a": "manifest" must be a relative path inside the provider root.',
            ],
            'MODE_COPY_FAILED' => [
                Message::MODE_COPY_FAILED,
                ['/vendor/p/a/stubs/a.txt', '/project/a.txt'],
                'Could not copy "/vendor/p/a/stubs/a.txt" to "/project/a.txt".',
            ],
            'MODE_DESTINATION_UNREADABLE' => [
                Message::MODE_DESTINATION_UNREADABLE,
                ['/project/a.txt'],
                'Could not read destination file "/project/a.txt".',
            ],
            'MODE_SOURCE_UNREADABLE' => [
                Message::MODE_SOURCE_UNREADABLE,
                ['/vendor/p/a/stubs/a.txt'],
                'Could not read source file "/vendor/p/a/stubs/a.txt".',
            ],
            'MODE_WRITE_FAILED' => [
                Message::MODE_WRITE_FAILED,
                ['/project/a.txt'],
                'Could not write to "/project/a.txt".',
            ],
            'PACKAGE_NOT_ALLOWED' => [
                Message::PACKAGE_NOT_ALLOWED,
                ['p/a'],
                'Package "p/a" is not authorized to scaffold files. Add it to extra.scaffold.allowed-packages.',
            ],
            'PATH_ABSOLUTE' => [
                Message::PATH_ABSOLUTE,
                ['destination', '/etc/passwd'],
                'Absolute path is not allowed in destination: "/etc/passwd".',
            ],
            'PATH_DIRECTORY_CREATE_FAILED' => [
                Message::PATH_DIRECTORY_CREATE_FAILED,
                ['/project/config'],
                'Could not create directory "/project/config".',
            ],
            'PATH_PROJECT_ROOT_INACCESSIBLE' => [
                Message::PATH_PROJECT_ROOT_INACCESSIBLE,
                ['/missing'],
                'Project root does not exist or is not accessible: "/missing".',
            ],
            'PATH_PROVIDER_ROOT_INACCESSIBLE' => [
                Message::PATH_PROVIDER_ROOT_INACCESSIBLE,
                ['/missing'],
                'Provider root does not exist or is not accessible: "/missing".',
            ],
            'PATH_RESERVED' => [
                Message::PATH_RESERVED,
                ['scaffold-lock.json'],
                'Destination "scaffold-lock.json" is reserved for the scaffold lock file.',
            ],
            'PATH_SYMLINK_ESCAPE' => [
                Message::PATH_SYMLINK_ESCAPE,
                ['Destination', 'link'],
                'Destination path escapes the root via symlink: "link".',
            ],
            'PATH_SYMLINK_UNRESOLVABLE' => [
                Message::PATH_SYMLINK_UNRESOLVABLE,
                ['Source', 'link.txt'],
                'Source path is a symlink that cannot be resolved: "link.txt".',
            ],
            'PATH_TRAVERSAL' => [
                Message::PATH_TRAVERSAL,
                ['source', '../stubs/a.txt'],
                'Path traversal detected in source: "../stubs/a.txt".',
            ],
            'PROVIDER_INSTALL_PATH_MISSING' => [
                Message::PROVIDER_INSTALL_PATH_MISSING,
                ['php-forge/meta'],
                'Provider "php-forge/meta" has no install path.',
            ],
            'REGEX_FAILED' => [
                Message::REGEX_FAILED,
                ['Backtrack limit exhausted'],
                'Regular expression failed: Backtrack limit exhausted.',
            ],
            'SCAFFOLD_ABORTED' => [
                Message::SCAFFOLD_ABORTED,
                ['Could not read lock file "/project/scaffold-lock.json".'],
                '[scaffold] Scaffolding aborted: Could not read lock file "/project/scaffold-lock.json".',
            ],
            'SCAFFOLD_FILES_FAILED' => [
                Message::SCAFFOLD_FILES_FAILED,
                [2, 'files failed'],
                '[scaffold] 2 files failed while scaffolding; see the messages above.',
            ],
            'SCAFFOLD_FRAGMENT_FAILED_FILE' => [
                Message::SCAFFOLD_FRAGMENT_FAILED_FILE,
                [],
                'file failed',
            ],
            'SCAFFOLD_FRAGMENT_FAILED_FILES' => [
                Message::SCAFFOLD_FRAGMENT_FAILED_FILES,
                [],
                'files failed',
            ],
            'SCAFFOLD_MANIFEST_LOAD_FAILED' => [
                Message::SCAFFOLD_MANIFEST_LOAD_FAILED,
                ['p/a', 'Manifest "copy" must declare at least one path.'],
                '[scaffold] Failed to load manifest for "p/a": Manifest "copy" must declare at least one path.',
            ],
            'SERVICE_CURRENT_FILE_UNREADABLE' => [
                Message::SERVICE_CURRENT_FILE_UNREADABLE,
                ['/project/a.txt'],
                'Could not read current file "/project/a.txt".',
            ],
            'SERVICE_STATUS_ENCODE_FAILED' => [
                Message::SERVICE_STATUS_ENCODE_FAILED,
                ['Malformed UTF-8 characters, possibly incorrectly encoded'],
                'Could not encode the status report: Malformed UTF-8 characters, possibly incorrectly encoded.',
            ],
            'SERVICE_STUB_NOT_FOUND' => [
                Message::SERVICE_STUB_NOT_FOUND,
                ['/vendor/p/a/stubs/a.txt'],
                'Stub not found: "/vendor/p/a/stubs/a.txt".',
            ],
            'SERVICE_STUB_UNREADABLE' => [
                Message::SERVICE_STUB_UNREADABLE,
                ['/vendor/p/a/stubs/a.txt'],
                'Could not read stub "/vendor/p/a/stubs/a.txt".',
            ],
        ];
    }
}
