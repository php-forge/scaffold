<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Merge;

/**
 * Represents the merge strategies available to the `append` mode, as declared in a manifest `merge` map.
 */
enum MergeStrategyKind: string
{
    /**
     * Section-aware merge for EditorConfig and INI files ({@see IniMergeStrategy}).
     */
    case Ini = 'ini';

    /**
     * Entry-based merge for ignore-style list files ({@see ListMergeStrategy}).
     */
    case ListFile = 'list';

    /**
     * Line-based fallback merge that appends missing lines at the end ({@see TextMergeStrategy}).
     */
    case Text = 'text';
}
