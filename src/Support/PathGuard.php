<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Support;

use Illuminate\Validation\ValidationException;

/**
 * Normalises the paths and names the browser sends, before any of them reaches a disk.
 *
 * A path is relative to the file manager's root (`''` is the root itself), uses `/` as the
 * separator and has no empty, `.`, `..` or hidden (dot) segment, no control character and no
 * scheme or drive prefix. Anything else is refused, never "repaired" into a different path,
 * so a request can only ever reach files under the root it was made for.
 */
final class PathGuard
{
    /** Characters no file or folder name may contain (they break Windows disks, URLs or shells). */
    private const FORBIDDEN_CHARACTERS = '/[\x00-\x1F\x7F\\\\\/:*?"<>|]/u';

    public const MAX_NAME_BYTES = 255;

    public const MAX_PATH_BYTES = 1024;

    /**
     * The normalised relative path, or null when the value is not a safe path.
     */
    public static function normalize(mixed $path): ?string
    {
        if ($path === null || $path === '') {
            return '';
        }

        if (! is_string($path) || strlen($path) > self::MAX_PATH_BYTES || ! mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        // Stream wrappers (`phar://`, `s3://`), Windows drives and UNC paths.
        if (str_contains($path, '://') || preg_match('/^[A-Za-z]:/', $path) === 1 || str_contains($path, '\\')) {
            return null;
        }

        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return '';
        }

        $segments = explode('/', $trimmed);

        foreach ($segments as $segment) {
            if (! self::isSafeSegment($segment)) {
                return null;
            }
        }

        return implode('/', $segments);
    }

    /**
     * The normalised path, or a validation error on `$attribute`.
     *
     * @throws ValidationException
     */
    public static function path(mixed $path, string $attribute = 'path'): string
    {
        $normalized = self::normalize($path);

        if ($normalized === null) {
            throw ValidationException::withMessages([$attribute => __('alfakit-file-manager::file-manager.errors.invalid_path')]);
        }

        return $normalized;
    }

    /**
     * A list of distinct, normalised, non-root paths.
     *
     *
     * @throws ValidationException
     *
     * @return list<string>
     */
    public static function paths(mixed $paths, int $max, string $attribute = 'paths'): array
    {
        if (! is_array($paths) || $paths === []) {
            throw ValidationException::withMessages([$attribute => __('alfakit-file-manager::file-manager.errors.nothing_selected')]);
        }

        if (count($paths) > $max) {
            throw ValidationException::withMessages([$attribute => __('alfakit-file-manager::file-manager.errors.too_many_items', ['max' => $max])]);
        }

        $normalized = [];

        foreach ($paths as $path) {
            $path = self::path($path, $attribute);

            if ($path === '') {
                throw ValidationException::withMessages([$attribute => __('alfakit-file-manager::file-manager.errors.invalid_path')]);
            }

            $normalized[$path] = true;
        }

        return array_keys($normalized);
    }

    /**
     * A safe file or folder name (one segment, not hidden, no forbidden characters).
     *
     * @throws ValidationException
     */
    public static function name(mixed $name, string $attribute = 'name'): string
    {
        $name = is_string($name) ? trim($name) : null;

        if ($name === null || $name === '') {
            throw ValidationException::withMessages([$attribute => __('alfakit-file-manager::file-manager.errors.name_required')]);
        }

        if (! self::isSafeSegment($name) || str_ends_with($name, '.')) {
            throw ValidationException::withMessages([$attribute => __('alfakit-file-manager::file-manager.errors.invalid_name')]);
        }

        return $name;
    }

    public static function isSafeSegment(string $segment): bool
    {
        return $segment !== ''
            && $segment !== '.'
            && $segment !== '..'
            && ! str_starts_with($segment, '.')
            && $segment === trim($segment)
            && strlen($segment) <= self::MAX_NAME_BYTES
            && mb_check_encoding($segment, 'UTF-8')
            && preg_match(self::FORBIDDEN_CHARACTERS, $segment) !== 1;
    }

    /**
     * `a/b` inside `a` (or the root `''`).
     */
    public static function isWithin(string $path, string $folder): bool
    {
        return $folder === '' || $path === $folder || str_starts_with($path, $folder.'/');
    }

    public static function parent(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? '' : substr($path, 0, $position);
    }

    public static function basename(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? $path : substr($path, $position + 1);
    }

    public static function join(string ...$parts): string
    {
        return implode('/', array_filter(array_map(static fn (string $part): string => trim($part, '/'), $parts), static fn (string $part): bool => $part !== ''));
    }
}
