<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\AlfakitFileManager\Support\FileTypes;
use AlfaSix\AlfakitFileManager\Support\PathGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function AlfaSix\AlfakitFileManager\Tests\fmAction;
use function AlfaSix\AlfakitFileManager\Tests\fmComponent;
use function AlfaSix\AlfakitFileManager\Tests\fmPlugin;
use function AlfaSix\AlfakitFileManager\Tests\fmPng;
use function AlfaSix\AlfakitFileManager\Tests\fmTemporaryUpload;
use function AlfaSix\AlfakitFileManager\Tests\fmToken;
use function AlfaSix\AlfakitFileManager\Tests\fmUser;
use function AlfaSix\AlfakitFileManager\Tests\inertiaGet;
use function AlfaSix\AlfakitFileManager\Tests\pageProps;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(AlfaSix\AlfakitFileManager\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    actingAs(fmUser());
});

dataset('traversal', [
    'parent' => '../secret.txt',
    'nested parent' => 'docs/../../secret.txt',
    'dot segment' => './docs',
    'backslash' => '..\\secret.txt',
    'windows drive' => 'C:/Windows/win.ini',
    'stream wrapper' => 'phar://evil.phar/x',
    'null byte' => "docs/a.txt\0.png",
    'hidden' => '.env',
    'trash' => '.trash',
    'hidden segment' => 'docs/.git/config',
    'empty segment' => 'docs//a.txt',
    'control character' => "docs/\x07a.txt",
]);

it('normalises safe paths', function (): void {
    expect(PathGuard::normalize(''))->toBe('')
        ->and(PathGuard::normalize('/docs/2024/'))->toBe('docs/2024')
        // A leading slash means the manager's root, never the disk's or the server's.
        ->and(PathGuard::normalize('/etc/passwd'))->toBe('etc/passwd')
        ->and(PathGuard::normalize('Rapports annuels/été 2024.pdf'))->toBe('Rapports annuels/été 2024.pdf')
        ->and(PathGuard::normalize(['docs']))->toBeNull()
        ->and(PathGuard::normalize(str_repeat('a/', 600)))->toBeNull();
});

it('refuses unsafe paths in every operation', function (string $path): void {
    Storage::disk('public')->put('docs/a.txt', 'a');
    Storage::disk('public')->put('.env', 'SECRET');
    $token = fmToken();

    expect(PathGuard::normalize($path))->toBeNull();

    foreach ([
        ['list', ['path' => $path]],
        ['tree', ['path' => $path]],
        ['search', ['path' => $path, 'query' => 'a']],
        ['createFolder', ['path' => $path, 'name' => 'x']],
        ['rename', ['path' => $path, 'name' => 'x.txt']],
        ['move', ['paths' => [$path], 'destination' => 'docs']],
        ['move', ['paths' => ['docs/a.txt'], 'destination' => $path]],
        ['copy', ['paths' => [$path], 'destination' => 'docs']],
        ['delete', ['paths' => [$path]]],
        ['details', ['path' => $path]],
        ['preview', ['path' => $path]],
        ['download', ['path' => $path]],
        ['zip', ['paths' => [$path]]],
        ['share', ['path' => $path, 'expiry' => 60]],
        ['upload', ['path' => $path, 'key' => 'tmp:x']],
    ] as [$action, $arguments]) {
        fmAction($action, $arguments, $token)->assertUnprocessable();
    }

    expect(Storage::disk('public')->get('.env'))->toBe('SECRET')
        ->and(Storage::disk('public')->get('docs/a.txt'))->toBe('a');
})->with('traversal');

it('refuses unsafe names', function (string $name): void {
    Storage::disk('public')->put('a.txt', 'a');

    fmAction('createFolder', ['path' => '', 'name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
    fmAction('rename', ['path' => 'a.txt', 'name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
})->with(['', '.', '..', '../x', 'a/b', 'a\\b', '.htaccess', 'name.', 'a:b', 'a*b', 'a?b', 'a"b', 'a<b', 'a|b']);

it('never lists or reads outside the root', function (): void {
    fmPlugin()->root('clients/acme');
    Storage::disk('public')->put('clients/acme/plan.pdf', 'acme');
    Storage::disk('public')->put('clients/globex/plan.pdf', 'globex');
    Storage::disk('public')->put('top.txt', 'top');

    $component = fmComponent(pageProps('/files-panel/files'));

    expect(array_column($component['listing']['entries'], 'path'))->toBe(['plan.pdf'])
        ->and(json_encode($component, JSON_UNESCAPED_SLASHES))->not->toContain('clients/acme');

    fmAction('list', ['path' => '../globex'])->assertUnprocessable();
    fmAction('details', ['path' => 'globex/plan.pdf'])->assertUnprocessable();
    fmAction('search', ['path' => '', 'query' => 'plan', 'recursive' => true])->assertOk()->assertJsonCount(1, 'result.entries');
});

it('refuses a root closure that escapes the disk', function (): void {
    fmPlugin()->root(fn (): string => '../../etc');

    inertiaGet('/files-panel/files')->assertServerError();
});

it('blocks dangerous uploads', function (string $name, string $contents, string $reason): void {
    // The panel's upload endpoint already refuses some detected types; take it out of the
    // way to check the file manager's own checks.
    config()->set('alfakit.uploads.blocked_mime_types', []);
    $key = fmTemporaryUpload(UploadedFile::fake()->createWithContent($name, $contents));

    $response = fmAction('upload', ['path' => '', 'key' => $key])->assertUnprocessable();

    expect($response->json('errors.key.0'))->toBe(__('alfakit-file-manager::file-manager.errors.'.$reason, ['name' => $name]))
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'php' => ['shell.php', '<?php system($_GET["c"]);', 'blocked_type'],
    'phtml' => ['shell.phtml', 'hello', 'blocked_type'],
    'phar' => ['tool.phar', 'hello', 'blocked_type'],
    'double extension' => ['shell.php.jpg', 'hello', 'blocked_type'],
    'html' => ['page.html', '<html><script>alert(1)</script></html>', 'blocked_type'],
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'blocked_type'],
    'script disguised as an image' => ['photo.jpg', '<?php echo 1;', 'blocked_type'],
    'html disguised as an image' => ['photo.png', '<html><body><script>alert(1)</script></body></html>', 'blocked_type'],
    'text disguised as an image' => ['photo.jpg', 'just some text', 'type_mismatch'],
    'text disguised as a pdf' => ['doc.pdf', 'just some text', 'type_mismatch'],
    'executable' => ['setup.exe', 'MZ', 'blocked_type'],
]);

it('rejects scripts at the panel upload endpoint already', function (): void {
    post('/files-panel/_alfakit/uploads', ['file' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')], ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

it('allows types unblocked with allowFileTypes()', function (): void {
    config()->set('alfakit.uploads.blocked_mime_types', []);
    fmPlugin()->allowFileTypes(['svg']);
    $key = fmTemporaryUpload(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'));

    fmAction('upload', ['path' => '', 'key' => $key])->assertOk();

    expect(Storage::disk('public')->exists('logo.svg'))->toBeTrue();
});

it('accepts only the accepted file types, detected from the contents', function (): void {
    fmPlugin()->acceptedFileTypes(['image/*', '.csv']);

    fmAction('upload', ['path' => '', 'key' => fmTemporaryUpload(fmPng())])->assertOk();
    fmAction('upload', ['path' => '', 'key' => fmTemporaryUpload(UploadedFile::fake()->createWithContent('list.csv', "a,b\n1,2"))])->assertOk();
    fmAction('upload', ['path' => '', 'key' => fmTemporaryUpload(UploadedFile::fake()->createWithContent('notes.txt', 'hello'))])
        ->assertUnprocessable()
        ->assertJsonPath('errors.key.0', __('alfakit-file-manager::file-manager.errors.not_accepted', ['name' => 'notes.txt']));

    expect(fmComponent(pageProps('/files-panel/files'))['upload']['accept'])->toBe(['image/*', '.csv']);
});

it('enforces the maximum upload size', function (): void {
    fmPlugin()->maxUploadSize(1);
    $key = fmTemporaryUpload(UploadedFile::fake()->createWithContent('big.txt', str_repeat('a', 2048)));

    fmAction('upload', ['path' => '', 'key' => $key])->assertUnprocessable()->assertJsonValidationErrors('key');

    expect(Storage::disk('public')->exists('big.txt'))->toBeFalse()
        ->and(fmComponent(pageProps('/files-panel/files'))['upload']['maxSize'])->toBe(1024);
});

it('detects contradicting types', function (): void {
    expect(FileTypes::rejection('a.jpg', 'image/jpeg', FileTypes::DEFAULT_BLOCKED_EXTENSIONS, FileTypes::DEFAULT_BLOCKED_MIME_TYPES, null))->toBeNull()
        ->and(FileTypes::rejection('a.mp4', 'audio/mp4', FileTypes::DEFAULT_BLOCKED_EXTENSIONS, FileTypes::DEFAULT_BLOCKED_MIME_TYPES, null))->toBeNull()
        ->and(FileTypes::rejection('a.docx', 'application/zip', FileTypes::DEFAULT_BLOCKED_EXTENSIONS, FileTypes::DEFAULT_BLOCKED_MIME_TYPES, null))->toBeNull()
        ->and(FileTypes::rejection('archive.tar.gz', 'application/gzip', FileTypes::DEFAULT_BLOCKED_EXTENSIONS, FileTypes::DEFAULT_BLOCKED_MIME_TYPES, null))->toBeNull()
        ->and(FileTypes::rejection('a.png', 'application/pdf', FileTypes::DEFAULT_BLOCKED_EXTENSIONS, FileTypes::DEFAULT_BLOCKED_MIME_TYPES, null))->toBe('type_mismatch');
});

it('closes the page and every action to users the plugin does not authorise', function (): void {
    $token = fmToken();
    fmPlugin()->authorize(fn (User $user): bool => $user->getAttribute('name') === 'Root');

    inertiaGet('/files-panel/files')->assertForbidden();
    fmAction('list', ['path' => ''], $token)->assertForbidden();

    actingAs(fmUser('Root'));
    inertiaGet('/files-panel/files')->assertOk();
});

it('checks the ability of each operation', function (): void {
    Storage::disk('public')->put('locked/a.txt', 'a');
    Storage::disk('public')->put('open/b.txt', 'b');
    fmPlugin()
        ->can('delete', fn (array $paths): bool => collect($paths)->every(fn (string $path): bool => ! str_starts_with($path, 'locked')))
        ->can(['rename', 'move'], false)
        ->can('upload', fn (string $path): bool => $path === 'open');
    $token = fmToken();

    fmAction('delete', ['paths' => ['locked/a.txt']], $token)->assertForbidden();
    fmAction('delete', ['paths' => ['open/b.txt']], $token)->assertOk();
    fmAction('rename', ['path' => 'locked/a.txt', 'name' => 'x.txt'], $token)->assertForbidden();
    fmAction('move', ['paths' => ['locked/a.txt'], 'destination' => 'open'], $token)->assertForbidden();
    fmAction('upload', ['path' => 'locked', 'key' => fmTemporaryUpload(fmPng())], $token)->assertForbidden();
    fmAction('upload', ['path' => 'open', 'key' => fmTemporaryUpload(fmPng())], $token)->assertOk();

    $permissions = fmComponent(pageProps('/files-panel/files'))['permissions'];

    expect($permissions)->toMatchArray(['rename' => false, 'move' => false, 'copy' => true])
        ->and(Storage::disk('public')->get('locked/a.txt'))->toBe('a');
});

it('refuses unknown abilities', function (): void {
    fmPlugin()->can('format', true);
})->throws(InvalidArgumentException::class);

it('is read only when asked', function (): void {
    Storage::disk('public')->put('a.txt', 'a');
    fmPlugin()->readOnly();
    $token = fmToken();

    fmAction('createFolder', ['path' => '', 'name' => 'x'], $token)->assertForbidden();
    fmAction('rename', ['path' => 'a.txt', 'name' => 'b.txt'], $token)->assertForbidden();
    fmAction('copy', ['paths' => ['a.txt'], 'destination' => ''], $token)->assertForbidden();
    fmAction('delete', ['paths' => ['a.txt']], $token)->assertForbidden();
    fmAction('upload', ['path' => '', 'key' => fmTemporaryUpload(fmPng())], $token)->assertForbidden();
    fmAction('download', ['path' => 'a.txt'], $token)->assertOk();

    $component = fmComponent(pageProps('/files-panel/files'));

    expect($component['readOnly'])->toBeTrue()
        ->and($component)->not->toHaveKey('upload')
        ->and($component['permissions'])->toMatchArray(['upload' => false, 'delete' => false, 'download' => true]);
});

it('does not accept a context token of another user', function (): void {
    $token = fmToken();

    actingAs(fmUser('Mallory'));

    fmAction('list', ['path' => ''], $token)->assertForbidden();
});
