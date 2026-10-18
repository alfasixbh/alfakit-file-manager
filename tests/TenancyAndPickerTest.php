<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitFileManager\Forms\Components\FileManagerPicker;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Models\Album;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Models\Document;
use Illuminate\Support\Facades\Storage;

use function AlfaSix\AlfakitFileManager\Tests\fmAction;
use function AlfaSix\AlfakitFileManager\Tests\fmComponent;
use function AlfaSix\AlfakitFileManager\Tests\fmPlugin;
use function AlfaSix\AlfakitFileManager\Tests\fmPng;
use function AlfaSix\AlfakitFileManager\Tests\fmTeam;
use function AlfaSix\AlfakitFileManager\Tests\fmTemporaryUpload;
use function AlfaSix\AlfakitFileManager\Tests\fmTenantUser;
use function AlfaSix\AlfakitFileManager\Tests\fmUser;
use function AlfaSix\AlfakitFileManager\Tests\inertiaGet;
use function AlfaSix\AlfakitFileManager\Tests\pageProps;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(AlfaSix\AlfakitFileManager\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * Ada belongs to Alpha and Beta, Bob to Beta only.
 *
 * @return array{alpha: Team, beta: Team}
 */
function fmTeams(): array
{
    $alpha = fmTeam('Alpha');
    $beta = fmTeam('Beta');
    $ada = fmTenantUser('Ada');
    $ada->teams()->attach([$alpha->getKey(), $beta->getKey()]);
    config()->set('auth.providers.users.model', $ada::class);
    actingAs($ada);

    return compact('alpha', 'beta');
}

// ---------------------------------------------------------------- Tenancy

it('gives every tenant its own root by default', function (): void {
    ['alpha' => $alpha, 'beta' => $beta] = fmTeams();
    Storage::disk('public')->put("tenants/{$alpha->getKey()}/alpha.txt", 'alpha');
    Storage::disk('public')->put("tenants/{$beta->getKey()}/beta.txt", 'beta');

    $alphaListing = fmComponent(pageProps('/files-team/alpha/files'))['listing'];
    $betaListing = fmComponent(pageProps('/files-team/beta/files'))['listing'];

    expect(array_column($alphaListing['entries'], 'name'))->toBe(['alpha.txt'])
        ->and(array_column($betaListing['entries'], 'name'))->toBe(['beta.txt']);
});

it('keeps uploads, folders and downloads inside the tenant', function (): void {
    ['alpha' => $alpha, 'beta' => $beta] = fmTeams();
    Storage::disk('public')->put("tenants/{$beta->getKey()}/secret.txt", 'beta');
    $token = (string) pageProps('/files-team/alpha/files')['token'];

    fmAction('createFolder', ['path' => '', 'name' => 'Reports'], $token, '/files-team/alpha')->assertOk();
    fmAction('upload', ['path' => 'Reports', 'key' => fmTemporaryUpload(fmPng(), '/files-team/alpha')], $token, '/files-team/alpha')->assertOk();

    expect(Storage::disk('public')->exists("tenants/{$alpha->getKey()}/Reports/photo.png"))->toBeTrue();

    // Beta's files can't be reached from Alpha, by path or by naming them.
    fmAction('details', ['path' => 'secret.txt'], $token, '/files-team/alpha')->assertUnprocessable();
    fmAction('details', ['path' => "../{$beta->getKey()}/secret.txt"], $token, '/files-team/alpha')->assertUnprocessable();

    // A link made under Alpha opens Alpha's file only, under Alpha's URL only.
    $url = (string) fmAction('download', ['path' => 'Reports/photo.png'], $token, '/files-team/alpha')->json('result.url');
    expect($url)->toContain('/files-team/alpha/files/file');
    \Pest\Laravel\get(str_replace('/files-team/alpha/', '/files-team/beta/', str_replace(config('app.url'), '', $url)))->assertForbidden();
});

it('does not run a tenant\'s token under another tenant', function (): void {
    fmTeams();
    $token = (string) pageProps('/files-team/alpha/files')['token'];

    fmAction('list', ['path' => ''], $token, '/files-team/beta')->assertNotFound();
});

it('keeps users out of tenants they do not belong to', function (): void {
    ['beta' => $beta] = fmTeams();
    $bob = fmTenantUser('Bob');
    $bob->teams()->attach($beta->getKey());
    actingAs($bob);

    inertiaGet('/files-team/alpha/files')->assertNotFound();
});

it('uses a custom root that receives the tenant', function (): void {
    ['alpha' => $alpha] = fmTeams();
    fmPlugin('files-team')->root(fn (Team $tenant, string $disk): string => "{$disk}-space/".$tenant->getAttribute('slug'));
    Storage::disk('public')->put('public-space/alpha/a.txt', 'a');

    expect(array_column(fmComponent(pageProps('/files-team/alpha/files'))['listing']['entries'], 'name'))->toBe(['a.txt']);
});

// ---------------------------------------------------------------- Picker

it('serialises the picker with its browser and the picked files', function (): void {
    actingAs(fmUser());
    Storage::disk('public')->put('invoices/march.pdf', '%PDF-1.4');
    $document = Document::query()->create(['title' => 'March', 'attachment' => 'invoices/march.pdf', 'gallery' => ['gone.png']]);

    $page = pageProps('/files-panel/documents/'.$document->getKey().'/edit');
    $fields = collect(json_decode((string) json_encode($page['content']), true))->pluck('components')->flatten(1)->keyBy('name');
    $attachment = $fields['attachment'];
    $gallery = $fields['gallery'];

    expect($attachment['type'])->toBe('FileManagerPicker')
        ->and($attachment['accept'])->toBe(['application/pdf'])
        ->and($attachment['files'][0])->toMatchArray(['value' => 'invoices/march.pdf', 'name' => 'march.pdf', 'kind' => 'pdf', 'selectable' => true])
        ->and($attachment['browser'])->toMatchArray(['type' => 'file-manager', 'mode' => 'picker', 'path' => 'invoices', 'disk' => 'public'])
        ->and($attachment['browser'])->not->toHaveKey('listing')
        ->and($attachment['browser']['token'])->toBeString()
        ->and($gallery['multiple'])->toBeTrue()
        ->and($gallery['maxFiles'])->toBe(3)
        ->and($gallery['files'][0])->toMatchArray(['value' => 'gone.png', 'missing' => true]);
});

it('browses through the file manager page with picker values', function (): void {
    actingAs(fmUser());
    Storage::disk('public')->put('invoices/march.pdf', '%PDF-1.4');
    Storage::disk('public')->put('invoices/photo.png', 'png');
    Storage::disk('public')->makeDirectory('invoices/2024');
    $document = Document::query()->create(['title' => 'March']);

    $page = pageProps('/files-panel/documents/'.$document->getKey().'/edit');
    $browser = collect(json_decode((string) json_encode($page['content']), true))->pluck('components')->flatten(1)->firstWhere('name', 'attachment')['browser'];

    $listing = fmAction('list', ['path' => 'invoices', 'picker' => true, 'accept' => ['application/pdf']], $browser['token'], '/files-panel')->assertOk()->json('result.listing');
    $entries = collect($listing['entries'])->keyBy('name');

    expect($entries['march.pdf'])->toMatchArray(['value' => 'invoices/march.pdf', 'selectable' => true])
        ->and($entries['photo.png']['selectable'])->toBeFalse()
        ->and($entries['2024']['selectable'])->toBeFalse();

    fmPlugin()->authorize(false);
    fmAction('list', ['path' => 'invoices', 'picker' => true], $browser['token'], '/files-panel')->assertForbidden();
});

it('saves picked files and validates them on the server', function (): void {
    actingAs(fmUser());
    Storage::disk('public')->put('invoices/march.pdf', '%PDF-1.4');
    Storage::disk('public')->put('photos/a.png', (string) fmPng()->getContent());
    Storage::disk('public')->put('photos/b.png', (string) fmPng()->getContent());
    Storage::disk('public')->put('photos/c.png', (string) fmPng()->getContent());
    Storage::disk('public')->put('photos/d.png', (string) fmPng()->getContent());
    Storage::disk('public')->put('notes.txt', 'x');
    $document = Document::query()->create(['title' => 'March']);
    $url = '/files-panel/documents/'.$document->getKey().'/edit';
    $token = (string) pageProps($url)['token'];

    $save = fn (array $data) => postJson('/files-panel/_alfakit/actions', [
        'token' => $token,
        'action' => 'save',
        'data' => ['title' => 'March', 'attachment' => null, 'gallery' => [], ...$data],
    ]);

    expect($save(['attachment' => 'invoices/march.pdf', 'gallery' => ['photos/a.png', 'photos/b.png']])->status())->toBeLessThan(400);

    expect($document->fresh()?->getAttribute('attachment'))->toBe('invoices/march.pdf')
        ->and($document->fresh()?->getAttribute('gallery'))->toBe(['photos/a.png', 'photos/b.png']);

    foreach ([
        ['attachment' => 'notes.txt'],
        ['attachment' => 'missing.pdf'],
        ['attachment' => '../../.env'],
        ['attachment' => '.trash/x.pdf'],
        ['attachment' => 'invoices'],
        ['gallery' => ['photos/a.png', 'notes.txt']],
        ['gallery' => ['photos/a.png', 'photos/b.png', 'photos/c.png', 'photos/d.png']],
    ] as $data) {
        expect([$data, $save($data)->status()])->toBe([$data, 422]);
    }

    // The same file picked twice counts once.
    expect($save(['gallery' => ['photos/a.png', 'photos/a.png']])->status())->toBeLessThan(400)
        ->and($document->fresh()?->getAttribute('gallery'))->toBe(['photos/a.png']);
});

it('only accepts files under the tenant root in the picker', function (): void {
    ['alpha' => $alpha, 'beta' => $beta] = fmTeams();
    Storage::disk('public')->put("tenants/{$alpha->getKey()}/a.pdf", '%PDF-1.4');
    Storage::disk('public')->put("tenants/{$beta->getKey()}/b.pdf", '%PDF-1.4');
    $panel = alfakit()->getPanel('files-team');
    alfakit()->setCurrentPanel($panel);
    alfakit()->setTenant($alpha);

    $picker = FileManagerPicker::make('file');

    expect($picker->isPickable("tenants/{$alpha->getKey()}/a.pdf"))->toBeTrue()
        ->and($picker->isPickable("tenants/{$beta->getKey()}/b.pdf"))->toBeFalse()
        ->and($picker->isPickable("tenants/{$alpha->getKey()}/../{$beta->getKey()}/b.pdf"))->toBeFalse();
});

it('needs the plugin on the panel', function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    FileManagerPicker::make('file')->getPlugin();
})->throws(LogicException::class);

it('adds picked files to a media library collection', function (): void {
    actingAs(fmUser());
    Storage::disk('public')->put('photos/a.png', (string) fmPng()->getContent());
    $album = Album::query()->create(['title' => 'Summer']);
    $token = (string) pageProps('/files-panel/albums/'.$album->getKey().'/edit')['token'];

    $response = postJson('/files-panel/_alfakit/actions', [
        'token' => $token,
        'action' => 'save',
        'data' => ['title' => 'Summer', 'photos' => ['photos/a.png']],
    ]);

    expect($response->status())->toBeLessThan(400)
        ->and($album->getMedia('gallery'))->toHaveCount(1)
        ->and($album->getFirstMedia('gallery')?->file_name)->toBe('a.png')
        // The original stays in the file manager.
        ->and(Storage::disk('public')->exists('photos/a.png'))->toBeTrue();
});
