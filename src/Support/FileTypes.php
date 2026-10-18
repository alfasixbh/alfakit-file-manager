<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Support;

use finfo;
use Symfony\Component\Mime\MimeTypes;

/**
 * What a file is (`image`, `pdf`, `text`…), and whether a file may be stored at all.
 *
 * Uploads are checked on their name and on the type detected from their contents: blocked
 * extensions (anywhere in the name for server-side scripts, so `shell.php.jpg` is refused),
 * blocked detected types, a mismatch between an image, audio, video or PDF extension and the
 * detected type, and the `acceptedFileTypes()` allowlist.
 */
final class FileTypes
{
    /**
     * Never stored by default: server-side scripts, HTML and SVG (scripts run when a public
     * disk serves them), executables and server configuration files.
     *
     * @var list<string>
     */
    public const DEFAULT_BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phtml', 'phar', 'phps', 'pgif', 'inc',
        'cgi', 'pl', 'py', 'pyc', 'rb', 'jsp', 'jspx', 'asp', 'aspx', 'asa', 'ashx', 'asmx', 'cer', 'cfm',
        'html', 'htm', 'xhtml', 'xht', 'shtml', 'shtm', 'mht', 'mhtml', 'svg', 'svgz', 'hta', 'swf',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'cpl', 'reg', 'lnk', 'vbs', 'vbe', 'wsf', 'ps1', 'psm1',
        'sh', 'bash', 'csh', 'ksh', 'zsh', 'jar', 'war', 'htaccess', 'htpasswd',
    ];

    /**
     * Extensions a web server may execute even when they are not the last one (`a.php.jpg`).
     *
     * @var list<string>
     */
    public const SCRIPT_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phtml', 'phar', 'phps', 'cgi', 'pl', 'py', 'rb',
        'jsp', 'jspx', 'asp', 'aspx', 'asa', 'shtml', 'sh',
    ];

    /**
     * Detected types that are never stored by default, whatever the file is called.
     *
     * @var list<string>
     */
    public const DEFAULT_BLOCKED_MIME_TYPES = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'application/x-httpd-php-source',
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/x-msdownload', 'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib',
        'application/x-mach-binary', 'application/x-elf', 'application/vnd.microsoft.portable-executable',
        'text/x-shellscript', 'application/x-sh', 'application/java-archive', 'application/x-msi',
    ];

    /** Types the browser may show inline (everything else downloads). */
    private const INLINE_MIME_PREFIXES = ['image/', 'video/', 'audio/'];

    private const TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'csv', 'tsv', 'log', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'env',
        'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue', 'svelte', 'css', 'scss', 'less', 'sql', 'java', 'kt',
        'go', 'rs', 'c', 'h', 'cpp', 'hpp', 'cs', 'swift', 'toml', 'lock', 'gitignore', 'srt', 'vtt',
    ];

    private const ARCHIVE_EXTENSIONS = ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz'];

    private const DOCUMENT_EXTENSIONS = ['doc', 'docx', 'odt', 'rtf', 'pages'];

    private const SPREADSHEET_EXTENSIONS = ['xls', 'xlsx', 'ods', 'numbers'];

    private const PRESENTATION_EXTENSIONS = ['ppt', 'pptx', 'odp', 'key'];

    /** Image formats every browser shows in an `<img>`. */
    private const PREVIEWABLE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon'];

    /**
     * The type of a file's contents (its first 64 KB), whatever its name says.
     *
     * @param  resource|null  $stream
     */
    public static function detect($stream): ?string
    {
        if (! is_resource($stream)) {
            return null;
        }

        $head = stream_get_contents($stream, 65536);

        if ($head === false || $head === '') {
            return $head === '' ? 'application/x-empty' : null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }

    public static function extension(string $name): string
    {
        $position = strrpos($name, '.');

        return $position === false || $position === 0 ? '' : strtolower(substr($name, $position + 1));
    }

    /**
     * The type a name suggests (for listings, where reading every file's type is too slow).
     */
    public static function guessMimeType(string $name): ?string
    {
        $extension = self::extension($name);

        if ($extension === '') {
            return null;
        }

        return MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null;
    }

    /**
     * `image`, `video`, `audio`, `pdf`, `text`, `archive`, `document`, `spreadsheet`,
     * `presentation` or `other`.
     */
    public static function kind(?string $mime, string $name): string
    {
        $extension = self::extension($name);
        $mime = $mime === null ? null : strtolower($mime);

        return match (true) {
            $mime !== null && str_starts_with($mime, 'image/') => 'image',
            $mime !== null && str_starts_with($mime, 'video/') => 'video',
            $mime !== null && str_starts_with($mime, 'audio/') => 'audio',
            $mime === 'application/pdf' || $extension === 'pdf' => 'pdf',
            in_array($extension, self::ARCHIVE_EXTENSIONS, true) => 'archive',
            in_array($extension, self::DOCUMENT_EXTENSIONS, true) => 'document',
            in_array($extension, self::SPREADSHEET_EXTENSIONS, true) => 'spreadsheet',
            in_array($extension, self::PRESENTATION_EXTENSIONS, true) => 'presentation',
            in_array($extension, self::TEXT_EXTENSIONS, true), $mime !== null && str_starts_with($mime, 'text/') => 'text',
            default => 'other',
        };
    }

    /**
     * Whether the preview dialog can show the file (images the browser draws, PDFs, players,
     * text). Blocked types never preview.
     */
    public static function isPreviewable(string $kind, ?string $mime): bool
    {
        return match ($kind) {
            'image' => $mime !== null && in_array(strtolower($mime), self::PREVIEWABLE_IMAGE_TYPES, true),
            'pdf', 'video', 'audio', 'text' => true,
            default => false,
        };
    }

    /**
     * Whether a type may be shown inline by the browser. HTML, SVG, XML and every other
     * active type are always downloaded, so a stored file can never run script on the panel's
     * origin.
     */
    public static function isSafeInline(?string $mime): bool
    {
        if ($mime === null) {
            return false;
        }

        $mime = strtolower($mime);

        if (in_array($mime, ['application/pdf', 'text/plain'], true)) {
            return true;
        }

        if ($mime === 'image/svg+xml') {
            return false;
        }

        foreach (self::INLINE_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$mime` / `$name` match one of the patterns: `image/*`, `application/pdf`,
     * `.csv` or `csv`.
     *
     * @param  list<string>  $patterns
     */
    public static function matches(array $patterns, ?string $mime, string $name): bool
    {
        $extension = self::extension($name);
        $mime = $mime === null ? null : strtolower($mime);

        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim($pattern));

            if ($pattern === '') {
                continue;
            }

            if (str_contains($pattern, '/')) {
                if ($mime === null) {
                    continue;
                }

                if (str_ends_with($pattern, '/*') ? str_starts_with($mime, substr($pattern, 0, -1)) : $mime === $pattern) {
                    return true;
                }

                continue;
            }

            if ($extension !== '' && ltrim($pattern, '.') === $extension) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a file may not be stored, as a translation key under `errors.`, or null when it may.
     *
     * @param  list<string>  $blockedExtensions
     * @param  list<string>  $blockedMimeTypes
     * @param  list<string>|null  $accepted
     */
    public static function rejection(string $name, ?string $detectedMime, array $blockedExtensions, array $blockedMimeTypes, ?array $accepted): ?string
    {
        $extension = self::extension($name);
        $segments = array_map('strtolower', array_slice(explode('.', $name), 1));

        if (in_array($extension, $blockedExtensions, true)) {
            return 'blocked_type';
        }

        // `shell.php.jpg`: Apache's `AddHandler` runs any file with `.php` in its name.
        foreach (array_slice($segments, 0, -1) as $segment) {
            if (in_array($segment, self::SCRIPT_EXTENSIONS, true) && in_array($segment, $blockedExtensions, true)) {
                return 'blocked_type';
            }
        }

        $detected = $detectedMime === null ? null : strtolower($detectedMime);

        if ($detected !== null && in_array($detected, $blockedMimeTypes, true)) {
            return 'blocked_type';
        }

        if ($detected !== null && $extension !== '' && self::contradicts($extension, $detected)) {
            return 'type_mismatch';
        }

        if ($accepted !== null && $accepted !== [] && ! self::matches($accepted, $detected ?? self::guessMimeType($name), $name)) {
            return 'not_accepted';
        }

        return null;
    }

    /**
     * An extension that promises an image, audio, video or PDF while the contents are
     * something else (a script renamed to `.jpg`).
     */
    private static function contradicts(string $extension, string $detected): bool
    {
        $expected = MimeTypes::getDefault()->getMimeTypes($extension);

        if ($expected === [] || in_array($detected, $expected, true)) {
            return false;
        }

        // Empty files and formats `finfo` doesn't know are not evidence of anything.
        if (in_array($detected, ['application/octet-stream', 'inode/x-empty', 'application/x-empty'], true)) {
            return false;
        }

        foreach (['image/', 'audio/', 'video/'] as $family) {
            $promises = array_filter($expected, static fn (string $type): bool => str_starts_with($type, $family)) !== [];

            if ($promises) {
                // Audio and video share containers (`.mp4` audio, `.webm`, `.ogg`).
                $media = in_array($family, ['audio/', 'video/'], true) && (str_starts_with($detected, 'audio/') || str_starts_with($detected, 'video/'));

                return ! str_starts_with($detected, $family) && ! $media;
            }
        }

        return in_array('application/pdf', $expected, true) && $detected !== 'application/pdf';
    }
}
