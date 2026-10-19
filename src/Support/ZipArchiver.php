<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Support;

use AlfaSix\Alfakit\Support\NumberFormat;
use AlfaSix\AlfakitFileManager\FileManagerPlugin;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/**
 * Builds the zip download of a selection. The plan (every file and its name in the archive)
 * is checked against `zipDownloads()`'s size and file limits before anything is read; hidden
 * files and the trash are never included.
 */
final class ZipArchiver
{
    /**
     * @param  list<string>  $paths
     *
     * @throws ValidationException
     *
     * @return array{files: array<string, string>, size: int} Archive names by path, total bytes
     */
    public static function plan(Drive $drive, array $paths, FileManagerPlugin $plugin): array
    {
        $maxFiles = $plugin->getMaxZipFiles();
        $maxBytes = $plugin->getMaxZipSize() * 1024;
        $files = [];
        $size = 0;
        $filesystem = $drive->filesystem();

        foreach ($paths as $path) {
            if ($drive->isFile($path)) {
                $candidates = [$path => PathGuard::basename($path)];
            } elseif ($drive->isFolder($path)) {
                $candidates = [];
                $base = PathGuard::basename($path);

                foreach ($filesystem->allFiles($drive->full($path)) as $file) {
                    $relative = $drive->relative($file);

                    if ($relative === null || PathGuard::normalize($relative) !== $relative) {
                        continue;
                    }

                    $candidates[$relative] = $base.'/'.substr($relative, strlen($path) + 1);
                }
            } else {
                continue;
            }

            foreach ($candidates as $file => $name) {
                // Two selected items with the same name: `a.txt`, `a (1).txt`.
                $files[$file] = self::uniqueName($name, $files);
                $size += (int) ($drive->entry($file)['size'] ?? 0);

                if (count($files) > $maxFiles) {
                    throw ValidationException::withMessages(['paths' => __('alfakit-file-manager::file-manager.errors.zip_too_many', ['max' => $maxFiles])]);
                }

                if ($size > $maxBytes) {
                    throw ValidationException::withMessages(['paths' => __('alfakit-file-manager::file-manager.errors.zip_too_large', ['max' => NumberFormat::fileSize($maxBytes, 0)])]);
                }
            }
        }

        if ($files === []) {
            throw ValidationException::withMessages(['paths' => __('alfakit-file-manager::file-manager.errors.zip_empty')]);
        }

        return ['files' => $files, 'size' => $size];
    }

    /**
     * Write the archive to a temporary file and return its path (the caller sends it with
     * `deleteFileAfterSend()`).
     *
     * @param  array<string, string>  $files  Archive names by path
     * @param  list<string>  $temporary  Temporary copies of remote files, to delete afterwards
     */
    public static function build(Drive $drive, array $files, array &$temporary): string
    {
        $target = tempnam(sys_get_temp_dir(), 'alfakit-zip-');

        if ($target === false) {
            throw new RuntimeException('A temporary file for the zip archive could not be created.');
        }

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The zip archive could not be created.');
        }

        foreach ($files as $path => $name) {
            $local = $drive->localPath($path);

            if ($local === null) {
                // Remote disks: stream each file to a temporary copy (never fully in memory).
                $stream = $drive->readStream($path);

                if ($stream === null) {
                    continue;
                }

                $copy = tempnam(sys_get_temp_dir(), 'alfakit-zip-file-');

                if ($copy === false) {
                    fclose($stream);

                    continue;
                }

                $temporary[] = $copy;
                $handle = fopen($copy, 'wb');

                if ($handle !== false) {
                    stream_copy_to_stream($stream, $handle);
                    fclose($handle);
                }

                fclose($stream);
                $local = $copy;
            }

            $zip->addFile($local, $name);
        }

        $zip->close();

        return $target;
    }

    /**
     * @param  array<string, string>  $taken
     */
    private static function uniqueName(string $name, array $taken): string
    {
        $names = array_flip($taken);

        if (! isset($names[$name])) {
            return $name;
        }

        $extension = FileTypes::extension($name);
        $base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);

        for ($index = 1; ; $index++) {
            $candidate = $base.' ('.$index.')'.($extension === '' ? '' : '.'.$extension);

            if (! isset($names[$candidate])) {
                return $candidate;
            }
        }
    }
}
