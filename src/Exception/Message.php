<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Exception;

use PHPForge\Scaffold\MessageTemplate;

/**
 * Represents the message templates of the exceptions thrown by the scaffold plugin.
 */
enum Message: string
{
    use MessageTemplate;

    /**
     * Error when the project manifest is not valid JSON.
     */
    case CONFIG_MANIFEST_INVALID_JSON = 'Project manifest "%s" is not valid JSON: %s.';

    /**
     * Error when the project manifest does not exist.
     */
    case CONFIG_MANIFEST_NOT_FOUND = 'Project manifest "%s" not found.';

    /**
     * Error when the project manifest does not decode to a JSON object.
     */
    case CONFIG_MANIFEST_NOT_OBJECT = 'Project manifest "%s" does not contain a JSON object.';

    /**
     * Error when the project manifest cannot be read.
     */
    case CONFIG_MANIFEST_UNREADABLE = 'Could not read project manifest "%s".';

    /**
     * Error when a native filesystem call fails, followed by the PHP warning it raised.
     */
    case FILESYSTEM_FAILED = '%s Reason: %s.';

    /**
     * Error when the lock file holds Git conflict markers and is not valid JSON.
     */
    case LOCK_CONFLICT_MARKERS = 'Lock file "%s" contains Git conflict markers and is not valid JSON (%s). Resolve the '
        . 'merge conflict and run the command again.';

    /**
     * Error when the lock file contents cannot be encoded as JSON.
     */
    case LOCK_ENCODE_FAILED = 'Could not encode lock file "%s": %s.';

    /**
     * Error when a file cannot be hashed.
     */
    case LOCK_HASH_FAILED = 'Could not hash file "%s".';

    /**
     * Error when the lock file is not valid JSON.
     */
    case LOCK_INVALID_JSON = 'Lock file "%s" is not valid JSON: %s.';

    /**
     * Error when a lock entry records an unknown mode.
     */
    case LOCK_MODE_UNKNOWN = 'Unknown mode "%s" in scaffold-lock.json.';

    /**
     * Error when the lock file does not decode to a JSON object.
     */
    case LOCK_NOT_OBJECT = 'Lock file "%s" does not contain a valid JSON object.';

    /**
     * Error when the lock file cannot be read.
     */
    case LOCK_UNREADABLE = 'Could not read lock file "%s".';

    /**
     * Error when the lock file cannot be written.
     */
    case LOCK_UNWRITABLE = 'Could not write lock file "%s".';

    /**
     * Error when the manifest `copy` list is empty.
     */
    case MANIFEST_COPY_EMPTY = 'Manifest "copy" must declare at least one path.';

    /**
     * Error when a manifest `copy` entry is neither a string nor a `from`/`to` object.
     */
    case MANIFEST_COPY_ENTRY_INVALID = 'Manifest "copy" entries must be either a string or an object with "from" and '
        . '"to" keys.';

    /**
     * Error when a manifest `copy` entry does not exist under the provider root.
     */
    case MANIFEST_COPY_ENTRY_NOT_FOUND = 'Scaffold copy entry "%s" does not exist under provider root "%s".';

    /**
     * Error when the manifest lacks the `copy` key or it is not an array.
     */
    case MANIFEST_COPY_MISSING = 'Manifest is missing required key "copy" or it is not an array.';

    /**
     * Error when the manifest `copy` value is an associative object instead of a list.
     */
    case MANIFEST_COPY_NOT_LIST = 'Manifest "copy" must be a sequential list of entries, not an associative object.';

    /**
     * Error when a manifest path entry is absolute.
     */
    case MANIFEST_ENTRY_ABSOLUTE = 'Manifest "%s" entry "%s" must be a relative path.';

    /**
     * Error when a manifest path entry is not a non-empty string.
     */
    case MANIFEST_ENTRY_EMPTY = 'Manifest "%s" entries must be non-empty strings.';

    /**
     * Error when a manifest path entry contains traversal segments.
     */
    case MANIFEST_ENTRY_TRAVERSAL = 'Manifest "%s" entry "%s" must not contain path traversal segments.';

    /**
     * Error when the manifest `exclude` value is not an array.
     */
    case MANIFEST_EXCLUDE_NOT_ARRAY = 'Manifest "exclude" must be an array when present.';

    /**
     * Error when the manifest declares an unknown key.
     */
    case MANIFEST_KEY_UNKNOWN = 'Manifest contains unknown key "%s". Allowed keys: %s.';

    /**
     * Error when the manifest `modes` or `merge` key is not an object.
     */
    case MANIFEST_MAP_NOT_OBJECT = 'Manifest "%s" must be an object when present.';

    /**
     * Error when a manifest `modes` or `merge` value is not one of the allowed values.
     */
    case MANIFEST_MAP_VALUE_INVALID = 'Manifest "%s" value for pattern "%s" has invalid %s "%s". Allowed: %s.';

    /**
     * Error when a manifest `modes` or `merge` value is not a string.
     */
    case MANIFEST_MAP_VALUE_NOT_STRING = 'Manifest "%s" value for pattern "%s" must be a string.';

    /**
     * Error when a provider file does not exist.
     */
    case MANIFEST_PROVIDER_FILE_NOT_FOUND = 'Provider "%s": %s not found at "%s".';

    /**
     * Error when a provider file does not decode to a JSON object.
     */
    case MANIFEST_PROVIDER_FILE_NOT_OBJECT = 'Provider "%s": %s "%s" must decode to an object.';

    /**
     * Error when a provider file cannot be read.
     */
    case MANIFEST_PROVIDER_FILE_UNREADABLE = 'Provider "%s": could not read %s "%s".';

    /**
     * Error when the provider `manifest` reference is not a non-empty string.
     */
    case MANIFEST_REFERENCE_EMPTY = 'Provider "%s": "manifest" must be a non-empty string.';

    /**
     * Error when the provider `manifest` reference points outside the provider root.
     */
    case MANIFEST_REFERENCE_NOT_RELATIVE = 'Provider "%s": "manifest" must be a relative path inside the provider '
        . 'root.';

    /**
     * Error when a source file cannot be copied to its destination.
     */
    case MODE_COPY_FAILED = 'Could not copy "%s" to "%s".';

    /**
     * Error when the destination file cannot be read.
     */
    case MODE_DESTINATION_UNREADABLE = 'Could not read destination file "%s".';

    /**
     * Error when the source file cannot be read.
     */
    case MODE_SOURCE_UNREADABLE = 'Could not read source file "%s".';

    /**
     * Error when the destination file cannot be written.
     */
    case MODE_WRITE_FAILED = 'Could not write to "%s".';

    /**
     * Error when a package is not in the scaffold allowlist.
     */
    case PACKAGE_NOT_ALLOWED = 'Package "%s" is not authorized to scaffold files. Add it to '
        . 'extra.scaffold.allowed-packages.';

    /**
     * Error when a path is absolute.
     */
    case PATH_ABSOLUTE = 'Absolute path is not allowed in %s: "%s".';

    /**
     * Error when a directory cannot be created.
     */
    case PATH_DIRECTORY_CREATE_FAILED = 'Could not create directory "%s".';

    /**
     * Error when the project root does not exist or is not accessible.
     */
    case PATH_PROJECT_ROOT_INACCESSIBLE = 'Project root does not exist or is not accessible: "%s".';

    /**
     * Error when the provider root does not exist or is not accessible.
     */
    case PATH_PROVIDER_ROOT_INACCESSIBLE = 'Provider root does not exist or is not accessible: "%s".';

    /**
     * Error when a destination addresses the scaffold lock file.
     */
    case PATH_RESERVED = 'Destination "%s" is reserved for the scaffold lock file.';

    /**
     * Error when a path escapes its root through a symlink.
     */
    case PATH_SYMLINK_ESCAPE = '%s path escapes the root via symlink: "%s".';

    /**
     * Error when a symlink in a path cannot be resolved.
     */
    case PATH_SYMLINK_UNRESOLVABLE = '%s path is a symlink that cannot be resolved: "%s".';

    /**
     * Error when a path contains traversal segments.
     */
    case PATH_TRAVERSAL = 'Path traversal detected in %s: "%s".';

    /**
     * Error when a resolved provider has no install path.
     */
    case PROVIDER_INSTALL_PATH_MISSING = 'Provider "%s" has no install path.';

    /**
     * Error when a PCRE function fails.
     */
    case REGEX_FAILED = 'Regular expression failed: %s.';

    /**
     * Error when the scaffold workflow aborts on an unexpected failure.
     */
    case SCAFFOLD_ABORTED = '[scaffold] Scaffolding aborted: %s';

    /**
     * Error when one or more files fail while scaffolding.
     */
    case SCAFFOLD_FILES_FAILED = '[scaffold] %d %s while scaffolding; see the messages above.';

    /**
     * Fragment that names a single failed file.
     */
    case SCAFFOLD_FRAGMENT_FAILED_FILE = 'file failed';

    /**
     * Fragment that names several failed files.
     */
    case SCAFFOLD_FRAGMENT_FAILED_FILES = 'files failed';

    /**
     * Error when a provider manifest cannot be loaded.
     */
    case SCAFFOLD_MANIFEST_LOAD_FAILED = '[scaffold] Failed to load manifest for "%s": %s';

    /**
     * Error when the current project file cannot be read.
     */
    case SERVICE_CURRENT_FILE_UNREADABLE = 'Could not read current file "%s".';

    /**
     * Error when the status report cannot be encoded as JSON.
     */
    case SERVICE_STATUS_ENCODE_FAILED = 'Could not encode the status report: %s.';

    /**
     * Error when a provider stub does not exist.
     */
    case SERVICE_STUB_NOT_FOUND = 'Stub not found: "%s".';

    /**
     * Error when a provider stub cannot be read.
     */
    case SERVICE_STUB_UNREADABLE = 'Could not read stub "%s".';
}
