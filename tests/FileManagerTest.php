<?php

declare(strict_types=1);

use AlfaSix\AlfakitFileManager\Support\Drive;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function AlfaSix\AlfakitFileManager\Tests\fmAction;
use function AlfaSix\AlfakitFileManager\Tests\fmComponent;
use function AlfaSix\AlfakitFileManager\Tests\fmPlugin;
use function AlfaSix\AlfakitFileManager\Tests\fmPng;
use function AlfaSix\AlfakitFileManager\Tests\fmTemporaryUpload;
use function AlfaSix\AlfakitFileManager\Tests\fmToken;
use function AlfaSix\AlfakitFileManager\Tests\fmUser;
use function AlfaSix\AlfakitFileManager\Tests\pageProps;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitFileManager\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    actingAs(fmUser());
});

/**
 * @param  array<string, mixed>  $listing
 * @return list<string>
 */
function fmNames(array $listing): array
{
    return array_column($listing['entries'], 'name');
}

it('renders the file manager page with the root listing and folder tree', function (): void {
    Storage::disk('public')->put('reports/q1.pdf', '%PDF-1.4');
    Storage::disk('public')->put('readme.txt', 'Hello');
    Storage::disk('public')->put('.env', 'SECRET=1');

    $component = fmComponent(pageProps('/files-panel/files'));

    expect($component['mode'])->toBe('manager')
        ->and($component['disk'])->toBe('public')
        ->and($component['disks'])->toBe([['name' => 'public', 'label' => 'Public'], ['name' => 'archive', 'label' => 'Archive']])
        ->and(fmNames($component['listing']))->toBe(['reports', 'readme.txt'])
        ->and($component['tree'])->toBe([['path' => 'reports', 'name' => 'reports', 'hasChildren' => false]])
        ->and($component['permissions'])->toMatchArray(['upload' => true, 'delete' => true, 'share' => true, 'zip' => true])
        ->and($component['upload']['maxSize'])->toBe(12288 * 1024)
        ->and($component['trash'])->toBeTrue()
        ->and($component['share']['publicLinks'])->toBeTrue()
        ->and($component['actions']['list'])->toBe('list');
});

it('opens the folder named in the query string, and ignores unsafe ones', function (): void {
    Storage::disk('archive')->put('2024/a.txt', 'a');

    $component = fmComponent(pageProps('/files-panel/files?disk=archive&path=2024'));
    expect($component['disk'])->toBe('archive')->and($component['path'])->toBe('2024');

    $component = fmComponent(pageProps('/files-panel/files?disk=local&path=../etc'));
    expect($component['disk'])->toBe('public')->and($component['path'])->toBe('');
});

it('lists a folder with breadcrumbs and entry details', function (): void {
    Storage::disk('public')->put('reports/2024/q1.pdf', '%PDF-1.4');
    Storage::disk('public')->put('reports/2024/photo.png', (string) fmPng()->getContent());

    $listing = fmAction('list', ['path' => 'reports/2024'])->assertOk()->json('result.listing');

    expect($listing['path'])->toBe('reports/2024')
        ->and($listing['breadcrumbs'])->toBe([['path' => 'reports', 'name' => 'reports'], ['path' => 'reports/2024', 'name' => '2024']])
        ->and($listing['entries'][1])->toMatchArray(['path' => 'reports/2024/q1.pdf', 'name' => 'q1.pdf', 'type' => 'file', 'kind' => 'pdf', 'size' => 8, 'extension' => 'pdf', 'mime' => 'application/pdf'])
        ->and($listing['entries'][0]['kind'])->toBe('image')
        ->and($listing['entries'][0]['thumbnail'])->toContain('/files-panel/files/file?')
        ->and($listing['entries'][1])->not->toHaveKey('thumbnail');
});

it('reports a listing as truncated beyond the limit', function (): void {
    fmPlugin()->maxListedEntries(2);

    foreach (['a', 'b', 'c'] as $name) {
        Storage::disk('public')->put("{$name}.txt", $name);
    }

    $listing = fmAction('list', ['path' => ''])->assertOk()->json('result.listing');

    expect($listing['entries'])->toHaveCount(2)->and($listing['truncated'])->toBeTrue();
});

it('loads sub-folders for the tree', function (): void {
    Storage::disk('public')->makeDirectory('a/b/c');
    Storage::disk('public')->makeDirectory('a/d');

    $result = fmAction('tree', ['path' => 'a'])->assertOk()->json('result');

    expect($result['nodes'])->toBe([
        ['path' => 'a/b', 'name' => 'b', 'hasChildren' => true],
        ['path' => 'a/d', 'name' => 'd', 'hasChildren' => false],
    ]);
});

it('searches the current folder, and below it when asked', function (): void {
    Storage::disk('public')->put('Invoice-1.pdf', 'x');
    Storage::disk('public')->put('2024/invoice-2.pdf', 'x');
    Storage::disk('public')->put('2024/receipt.pdf', 'x');

    $here = fmAction('search', ['path' => '', 'query' => 'invoice'])->assertOk()->json('result');
    $everywhere = fmAction('search', ['path' => '', 'query' => 'INVOICE', 'recursive' => true])->assertOk()->json('result');

    expect(array_column($here['entries'], 'path'))->toBe(['Invoice-1.pdf'])
        ->and(array_column($everywhere['entries'], 'path'))->toBe(['Invoice-1.pdf', '2024/invoice-2.pdf'])
        ->and($everywhere['truncated'])->toBeFalse();
});

it('stops a recursive search at the result limit', function (): void {
    fmPlugin()->recursiveSearch(maxResults: 2);

    foreach (range(1, 4) as $index) {
        Storage::disk('public')->put("deep/{$index}/match-{$index}.txt", 'x');
    }

    $result = fmAction('search', ['path' => '', 'query' => 'match', 'recursive' => true])->assertOk()->json('result');

    expect($result['entries'])->toHaveCount(2)->and($result['truncated'])->toBeTrue();
});

it('creates folders and refuses duplicates', function (): void {
    $result = fmAction('createFolder', ['path' => '', 'name' => 'Contracts', 'current' => ''])->assertOk()->json('result');

    expect(Storage::disk('public')->directoryExists('Contracts'))->toBeTrue()
        ->and($result['path'])->toBe('Contracts')
        ->and(fmNames($result['listing']))->toBe(['Contracts'])
        ->and($result['nodes'][0]['path'])->toBe('Contracts')
        ->and($result['notifications'][0]['title'])->toBe('Folder “Contracts” created');

    fmAction('createFolder', ['path' => '', 'name' => 'Contracts'])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('renames files and folders', function (): void {
    Storage::disk('public')->put('docs/draft.txt', 'v1');

    fmAction('rename', ['path' => 'docs/draft.txt', 'name' => 'final.txt'])->assertOk();
    fmAction('rename', ['path' => 'docs', 'name' => 'Documents'])->assertOk();

    expect(Storage::disk('public')->get('Documents/final.txt'))->toBe('v1')
        ->and(Storage::disk('public')->exists('docs'))->toBeFalse();
});

it('renames when only the case changes', function (): void {
    Storage::disk('public')->put('photos/a.txt', 'a');

    fmAction('rename', ['path' => 'photos', 'name' => 'Photos'])->assertOk();

    expect(Storage::disk('public')->get('Photos/a.txt'))->toBe('a');
});

it('refuses to rename onto an existing item or to a blocked extension', function (): void {
    Storage::disk('public')->put('a.txt', 'a');
    Storage::disk('public')->put('b.txt', 'b');

    fmAction('rename', ['path' => 'a.txt', 'name' => 'b.txt'])->assertUnprocessable()->assertJsonValidationErrors('name');
    fmAction('rename', ['path' => 'a.txt', 'name' => 'a.php'])->assertUnprocessable()->assertJsonValidationErrors('name');
    fmAction('rename', ['path' => 'a.txt', 'name' => 'a.php.txt'])->assertUnprocessable()->assertJsonValidationErrors('name');

    expect(Storage::disk('public')->get('a.txt'))->toBe('a');
});

it('moves items, renaming them when the name is taken', function (): void {
    Storage::disk('public')->put('in/report.pdf', 'new');
    Storage::disk('public')->put('in/sub/nested.txt', 'nested');
    Storage::disk('public')->put('archive/report.pdf', 'old');

    $result = fmAction('move', ['paths' => ['in/report.pdf', 'in/sub'], 'destination' => 'archive', 'current' => 'in'])->assertOk()->json('result');

    expect($result['paths'])->toBe(['archive/report (1).pdf', 'archive/sub'])
        ->and(fmNames($result['listing']))->toBe([])
        ->and(Storage::disk('public')->get('archive/report.pdf'))->toBe('old')
        ->and(Storage::disk('public')->get('archive/report (1).pdf'))->toBe('new')
        ->and(Storage::disk('public')->get('archive/sub/nested.txt'))->toBe('nested')
        ->and(Storage::disk('public')->exists('in/sub'))->toBeFalse()
        ->and($result['notifications'][0]['title'])->toBe('2 items moved');
});

it('refuses to move a folder into itself', function (): void {
    Storage::disk('public')->put('a/b/file.txt', 'x');

    fmAction('move', ['paths' => ['a'], 'destination' => 'a/b'])->assertUnprocessable()->assertJsonValidationErrors('destination');
    fmAction('copy', ['paths' => ['a'], 'destination' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('destination');

    expect(Storage::disk('public')->get('a/b/file.txt'))->toBe('x');
});

it('copies files and folders', function (): void {
    Storage::disk('public')->put('a/file.txt', 'x');
    Storage::disk('public')->put('a/inner/deep.txt', 'y');

    fmAction('copy', ['paths' => ['a/file.txt'], 'destination' => 'a'])->assertOk();
    fmAction('copy', ['paths' => ['a'], 'destination' => ''])->assertOk();

    expect(Storage::disk('public')->get('a/file (1).txt'))->toBe('x')
        ->and(Storage::disk('public')->get('a (1)/inner/deep.txt'))->toBe('y')
        ->and(Storage::disk('public')->get('a/file.txt'))->toBe('x');
});

it('limits how many items one operation may touch', function (): void {
    fmPlugin()->maxItemsPerOperation(2);

    foreach (range(1, 3) as $index) {
        Storage::disk('public')->put("big/{$index}.txt", 'x');
    }

    Storage::disk('public')->makeDirectory('target');

    fmAction('copy', ['paths' => ['big'], 'destination' => 'target'])->assertUnprocessable()->assertJsonValidationErrors('paths');
    fmAction('delete', ['paths' => ['big/1.txt', 'big/2.txt', 'big/3.txt']])->assertUnprocessable()->assertJsonValidationErrors('paths');
});

it('moves deleted items to the trash and restores them', function (): void {
    Storage::disk('public')->put('docs/a.txt', 'a');
    Storage::disk('public')->put('docs/b/c.txt', 'c');

    $result = fmAction('delete', ['paths' => ['docs/a.txt', 'docs/b'], 'current' => 'docs'])->assertOk()->json('result');

    expect(fmNames($result['listing']))->toBe([])
        ->and(Storage::disk('public')->exists('docs/a.txt'))->toBeFalse()
        ->and($result['notifications'][0]['title'])->toBe('2 items moved to the trash');

    // The trash is hidden from listings and searches.
    expect(fmNames(fmAction('list', ['path' => ''])->json('result.listing')))->toBe(['docs'])
        ->and(fmAction('search', ['path' => '', 'query' => 'a', 'recursive' => true])->json('result.entries'))->toBe([]);

    $trash = fmAction('list', ['trash' => true])->assertOk()->json('result.listing');

    expect($trash['trash'])->toBeTrue()
        ->and(fmNames($trash))->toEqualCanonicalizing(['a.txt', 'b'])
        ->and(collect($trash['entries'])->firstWhere('name', 'a.txt')['trashed']['originalPath'])->toBe('docs/a.txt');

    $ids = array_column($trash['entries'], 'path');
    fmAction('restore', ['paths' => $ids])->assertOk();

    expect(Storage::disk('public')->get('docs/a.txt'))->toBe('a')
        ->and(Storage::disk('public')->get('docs/b/c.txt'))->toBe('c')
        ->and(fmAction('list', ['trash' => true])->json('result.listing.entries'))->toBe([]);
});

it('restores into a re-created folder and next to a newer file of the same name', function (): void {
    Storage::disk('public')->put('gone/a.txt', 'old');
    fmAction('delete', ['paths' => ['gone']])->assertOk();
    Storage::disk('public')->put('gone/a.txt', 'new');

    $id = fmAction('list', ['trash' => true])->json('result.listing.entries.0.path');
    $result = fmAction('restore', ['paths' => [$id]])->assertOk()->json('result');

    expect($result['paths'])->toBe(['gone (1)'])
        ->and(Storage::disk('public')->get('gone (1)/a.txt'))->toBe('old');
});

it('deletes for good from the trash, permanently, without a trash, and empties the trash', function (): void {
    Storage::disk('public')->put('a.txt', 'a');
    Storage::disk('public')->put('b.txt', 'b');
    Storage::disk('public')->put('c.txt', 'c');

    fmAction('delete', ['paths' => ['a.txt', 'b.txt']])->assertOk();
    $ids = array_column(fmAction('list', ['trash' => true])->json('result.listing.entries'), 'path');

    fmAction('delete', ['paths' => [$ids[0]], 'trash' => true])->assertOk();
    expect(fmAction('list', ['trash' => true])->json('result.listing.entries'))->toHaveCount(1);

    fmAction('emptyTrash')->assertOk();
    expect(fmAction('list', ['trash' => true])->json('result.listing.entries'))->toBe([])
        ->and(Storage::disk('public')->exists(Drive::TRASH))->toBeFalse();

    fmAction('delete', ['paths' => ['c.txt'], 'permanent' => true])->assertOk();
    expect(Storage::disk('public')->exists('c.txt'))->toBeFalse()
        ->and(Storage::disk('public')->exists(Drive::TRASH))->toBeFalse();

    fmPlugin()->trash(false);
    Storage::disk('public')->put('d.txt', 'd');
    fmAction('delete', ['paths' => ['d.txt']])->assertOk();
    expect(Storage::disk('public')->exists('d.txt'))->toBeFalse()
        ->and(Storage::disk('public')->exists(Drive::TRASH))->toBeFalse();
    fmAction('list', ['trash' => true])->assertNotFound();
});

it('prunes items trashed longer than the retention period', function (): void {
    Storage::disk('public')->put('old.txt', 'old');
    fmAction('delete', ['paths' => ['old.txt']])->assertOk();

    $this->travel(31)->days();
    Storage::disk('public')->put('new.txt', 'new');
    fmAction('delete', ['paths' => ['new.txt']])->assertOk();

    expect(fmNames(fmAction('list', ['trash' => true])->json('result.listing')))->toBe(['new.txt']);
});

it('rejects trash ids that are not ids', function (string $id): void {
    fmAction('restore', ['paths' => [$id]])->assertUnprocessable();
    fmAction('delete', ['paths' => [$id], 'trash' => true])->assertUnprocessable();
})->with(['../a.txt', '01HZZZZZZZZZZZZZZZZZZZZZZZ/../../x', 'docs']);

it('uploads files from the temporary upload endpoint, renaming them when the name is taken', function (): void {
    Storage::disk('public')->put('photos/photo.png', 'existing');
    $key = fmTemporaryUpload(fmPng());

    $result = fmAction('upload', ['path' => 'photos', 'key' => $key, 'current' => 'photos'])->assertOk()->json('result');

    expect($result['path'])->toBe('photos/photo (1).png')
        ->and($result['entry'])->toMatchArray(['name' => 'photo (1).png', 'kind' => 'image'])
        ->and(fmNames($result['listing']))->toBe(['photo (1).png', 'photo.png'])
        ->and(Storage::disk('public')->get('photos/photo.png'))->toBe('existing');

    // The temporary upload is used up.
    fmAction('upload', ['path' => 'photos', 'key' => $key])->assertUnprocessable()->assertJsonValidationErrors('key');
});

it('cleans up names of uploaded files', function (): void {
    $key = fmTemporaryUpload(UploadedFile::fake()->createWithContent('..hidden:notes?.txt', 'hello'));

    $path = fmAction('upload', ['path' => '', 'key' => $key])->assertOk()->json('result.path');

    expect($path)->toBe('hidden-notes-.txt')->and(Storage::disk('public')->get($path))->toBe('hello');
});

it('only uses temporary uploads of the same user', function (): void {
    $key = fmTemporaryUpload(fmPng());

    actingAs(fmUser('Mallory'));

    fmAction('upload', ['path' => '', 'key' => $key])->assertUnprocessable()->assertJsonValidationErrors('key');
});

it('replaces a file with an edited upload', function (): void {
    Storage::disk('public')->put('photo.png', 'old');
    $key = fmTemporaryUpload(fmPng('edited.png'));

    fmAction('replace', ['path' => 'photo.png', 'key' => $key])->assertOk();

    expect(Storage::disk('public')->get('photo.png'))->toBe((string) fmPng()->getContent())
        ->and(Storage::disk('public')->exists('edited.png'))->toBeFalse();
});

it('shows details with image dimensions, and folder item counts', function (): void {
    Storage::disk('public')->put('img/photo.png', (string) fmPng()->getContent());
    Storage::disk('public')->put('img/other.txt', 'x');

    $file = fmAction('details', ['path' => 'img/photo.png'])->assertOk()->json('result.details');
    $folder = fmAction('details', ['path' => 'img'])->assertOk()->json('result.details');

    expect($file)->toMatchArray(['name' => 'photo.png', 'mime' => 'image/png', 'width' => 2, 'height' => 3, 'location' => 'img', 'links' => 0])
        ->and($folder)->toMatchArray(['type' => 'folder', 'items' => 2, 'location' => '']);
});

it('previews text files up to the size cap, and gives media an inline link', function (): void {
    fmPlugin()->textPreviewMaxSize(1);
    Storage::disk('public')->put('notes.md', str_repeat('a', 1500));
    Storage::disk('public')->put('small.txt', 'Hello ✓');
    Storage::disk('public')->put('doc.pdf', '%PDF-1.4');
    Storage::disk('public')->put('binary.txt', "a\0b");
    Storage::disk('public')->put('archive.zip', 'PK');

    $truncated = fmAction('preview', ['path' => 'notes.md'])->assertOk()->json('result.preview');
    $small = fmAction('preview', ['path' => 'small.txt'])->json('result.preview');
    $pdf = fmAction('preview', ['path' => 'doc.pdf'])->json('result.preview');

    expect($truncated)->toMatchArray(['kind' => 'text', 'truncated' => true])
        ->and(strlen($truncated['text']))->toBe(1024)
        ->and($small)->toMatchArray(['text' => 'Hello ✓'])->not->toHaveKey('truncated')
        ->and($pdf['kind'])->toBe('pdf')
        ->and($pdf['url'])->toContain('mode=i')
        ->and($pdf['downloadUrl'])->toContain('mode=d')
        ->and(fmAction('preview', ['path' => 'binary.txt'])->json('result.preview.previewable'))->toBeFalse()
        ->and(fmAction('preview', ['path' => 'archive.zip'])->json('result.preview.previewable'))->toBeFalse();
});

it('works on every configured disk and refuses others', function (): void {
    Storage::disk('archive')->put('old.txt', 'x');

    expect(fmNames(fmAction('list', ['disk' => 'archive', 'path' => ''])->assertOk()->json('result.listing')))->toBe(['old.txt']);

    fmAction('list', ['disk' => 'local', 'path' => ''])->assertUnprocessable()->assertJsonValidationErrors('disk');
    fmAction('list', ['disk' => null, 'path' => ''])->assertUnprocessable()->assertJsonValidationErrors('disk');
});

it('never sends the disk root or absolute paths to the browser', function (): void {
    Storage::disk('public')->put('a/b.txt', 'x');
    $root = Storage::disk('public')->path('');

    $page = json_encode(pageProps('/files-panel/files'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $details = json_encode(fmAction('details', ['path' => 'a/b.txt'])->json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect($page)->not->toContain($root)->and($details)->not->toContain($root);
});

it('refuses unknown actions', function (): void {
    fmAction('format', [], fmToken())->assertNotFound();
});
