<?php

declare(strict_types=1);

use AlfaSix\AlfakitFileManager\Models\FileManagerLink;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

use function AlfaSix\AlfakitFileManager\Tests\fmAction;
use function AlfaSix\AlfakitFileManager\Tests\fmComponent;
use function AlfaSix\AlfakitFileManager\Tests\fmPlugin;
use function AlfaSix\AlfakitFileManager\Tests\fmPng;
use function AlfaSix\AlfakitFileManager\Tests\fmToken;
use function AlfaSix\AlfakitFileManager\Tests\fmUser;
use function AlfaSix\AlfakitFileManager\Tests\pageProps;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(AlfaSix\AlfakitFileManager\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    actingAs(fmUser());
});

/**
 * @return TestResponse<Symfony\Component\HttpFoundation\Response>
 */
function fmVisit(string $url): TestResponse
{
    return get(str_replace(config('app.url'), '', $url));
}

/**
 * The contents of a file response (local files are sent as BinaryFileResponse).
 *
 * @param  TestResponse<Symfony\Component\HttpFoundation\Response>  $response
 */
function fmBody(TestResponse $response): string
{
    $base = $response->baseResponse;

    return $base instanceof BinaryFileResponse ? (string) file_get_contents($base->getFile()->getPathname()) : $response->streamedContent();
}

/**
 * @param  TestResponse<Symfony\Component\HttpFoundation\Response>  $response
 * @return array<string, string>
 */
function fmZipEntries(TestResponse $response): array
{
    $base = $response->baseResponse;
    expect($base)->toBeInstanceOf(BinaryFileResponse::class);

    $zip = new ZipArchive;
    $zip->open($base instanceof BinaryFileResponse ? $base->getFile()->getPathname() : '');
    $entries = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);
        $entries[$name] = (string) $zip->getFromIndex($index);
    }

    $zip->close();
    ksort($entries);

    return $entries;
}

it('downloads a file through a signed panel link', function (): void {
    Storage::disk('public')->put('docs/report.pdf', '%PDF-1.4 report');

    $url = fmAction('download', ['path' => 'docs/report.pdf'])->assertOk()->json('result.url');
    $response = fmVisit($url)->assertOk();

    expect(fmBody($response))->toBe('%PDF-1.4 report')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('report.pdf')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($url)->not->toContain(Storage::disk('public')->path(''));
});

it('refuses tampered, expired and unsigned file links', function (): void {
    Storage::disk('public')->put('a.txt', 'a');
    Storage::disk('public')->put('b.txt', 'b');

    $url = fmAction('download', ['path' => 'a.txt'])->json('result.url');

    fmVisit(str_replace('a.txt', 'b.txt', $url))->assertForbidden();
    fmVisit(preg_replace('/&signature=[^&]+/', '', $url) ?? '')->assertForbidden();
    get('/files-panel/files/file?disk=public&path=a.txt&mode=d')->assertForbidden();

    $this->travel(2)->hours();
    fmVisit($url)->assertForbidden();
});

it('shows only safe types inline, sandboxed', function (): void {
    fmPlugin()->allowFileTypes(['svg', 'html']);
    Storage::disk('public')->put('photo.png', (string) fmPng()->getContent());
    Storage::disk('public')->put('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    Storage::disk('public')->put('doc.pdf', '%PDF-1.4');

    $png = fmVisit(fmAction('preview', ['path' => 'photo.png'])->json('result.preview.url'))->assertOk();
    $pdf = fmVisit(fmAction('preview', ['path' => 'doc.pdf'])->json('result.preview.url'))->assertOk();
    $svg = fmVisit(str_replace(['photo.png'], ['logo.svg'], (string) fmAction('preview', ['path' => 'photo.png'])->json('result.preview.url')));

    expect($png->headers->get('Content-Type'))->toBe('image/png')
        ->and($png->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($png->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($pdf->headers->has('Content-Security-Policy'))->toBeFalse()
        // A signature for another file never works.
        ->and($svg->status())->toBe(403);
});

it('forces stored HTML and SVG files to download', function (): void {
    Storage::disk('public')->put('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $url = fmAction('download', ['path' => 'logo.svg'])->json('result.url');
    $inline = str_replace('mode=d', 'mode=i', $url);
    $response = fmVisit($url)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and(fmVisit($inline)->status())->toBe(403);
});

it('rechecks the download ability when the link is opened', function (): void {
    Storage::disk('public')->put('a.txt', 'a');
    $url = fmAction('download', ['path' => 'a.txt'])->json('result.url');

    fmPlugin()->can('download', false);

    fmVisit($url)->assertForbidden();
    fmAction('download', ['path' => 'a.txt'])->assertForbidden();
});

it('rate limits downloads', function (): void {
    fmPlugin()->rateLimits(downloadsPerMinute: 2);
    Storage::disk('public')->put('a.txt', 'a');
    $url = fmAction('download', ['path' => 'a.txt'])->json('result.url');

    fmVisit($url)->assertOk();
    fmVisit($url)->assertOk();
    fmVisit($url)->assertStatus(429);
});

it('downloads selections as a zip archive', function (): void {
    Storage::disk('public')->put('docs/a.txt', 'a');
    Storage::disk('public')->put('docs/sub/b.txt', 'b');
    Storage::disk('public')->put('docs/.secret', 'hidden');
    Storage::disk('public')->put('c.txt', 'c');

    $url = fmAction('zip', ['paths' => ['docs', 'c.txt']])->assertOk()->json('result.url');
    $response = fmVisit($url)->assertOk();

    expect(fmZipEntries($response))->toBe(['c.txt' => 'c', 'docs/a.txt' => 'a', 'docs/sub/b.txt' => 'b'])
        ->and($response->headers->get('Content-Disposition'))->toContain('files.zip');
});

it('keeps zip archives within the file and size limits', function (): void {
    Storage::disk('public')->put('a.txt', str_repeat('a', 700));
    Storage::disk('public')->put('b.txt', str_repeat('b', 700));
    Storage::disk('public')->put('c.txt', 'c');

    fmPlugin()->zipDownloads(maxSizeKb: 1);
    fmAction('zip', ['paths' => ['a.txt', 'b.txt']])->assertUnprocessable()->assertJsonValidationErrors('paths');

    fmPlugin()->zipDownloads(maxFiles: 2);
    fmAction('zip', ['paths' => ['a.txt', 'b.txt', 'c.txt']])->assertUnprocessable()->assertJsonValidationErrors('paths');

    fmPlugin()->zipDownloads(false);
    fmAction('zip', ['paths' => ['a.txt']])->assertNotFound();
    expect(fmComponent(pageProps('/files-panel/files'))['permissions']['zip'])->toBeFalse();
});

it('rate limits zip archives', function (): void {
    fmPlugin()->rateLimits(zipsPerMinute: 1);
    Storage::disk('public')->put('a.txt', 'a');
    $token = fmToken();

    fmAction('zip', ['paths' => ['a.txt']], $token)->assertOk();
    fmAction('zip', ['paths' => ['a.txt']], $token)->assertUnprocessable()->assertJsonValidationErrors('paths');
});

it('only lets the user who asked for an archive download it', function (): void {
    Storage::disk('public')->put('a.txt', 'a');
    $url = fmAction('zip', ['paths' => ['a.txt']])->json('result.url');

    actingAs(fmUser('Mallory'));

    fmVisit($url)->assertNotFound();
});

it('shares files in apps that use immutable dates (the starter kits)', function (): void {
    Illuminate\Support\Facades\Date::use(Carbon\CarbonImmutable::class);

    try {
        fmPlugin()->publicLinks(false);
        Storage::disk('public')->put('docs/brochure.pdf', '%PDF-1.4 brochure');

        expect(fmAction('share', ['path' => 'docs/brochure.pdf', 'expiry' => 60])->assertOk()->json('result.url'))->toContain('/alfakit-files/share?');
    } finally {
        Illuminate\Support\Facades\Date::useDefault();
    }
});

it('shares files with signed temporary links anyone can open', function (): void {
    fmPlugin()->publicLinks(false);
    Storage::disk('public')->put('docs/brochure.pdf', '%PDF-1.4 brochure');

    $result = fmAction('share', ['path' => 'docs/brochure.pdf', 'expiry' => 60])->assertOk()->json('result');

    expect($result['url'])->toContain('/alfakit-files/share?')
        ->and($result['url'])->not->toContain('docs/brochure.pdf')
        ->and($result['expiresAt'])->not->toBeNull();

    auth()->logout();

    $response = fmVisit($result['url'])->assertOk();
    expect(fmBody($response))->toBe('%PDF-1.4 brochure');

    $this->travel(61)->minutes();
    fmVisit($result['url'])->assertForbidden();
});

it('only offers the configured expiries', function (): void {
    fmPlugin()->publicLinks(false)->shareLinks(expiries: [30]);
    Storage::disk('public')->put('a.txt', 'a');

    fmAction('share', ['path' => 'a.txt', 'expiry' => 60])->assertUnprocessable()->assertJsonValidationErrors('expiry');
    fmAction('share', ['path' => 'a.txt', 'expiry' => null])->assertUnprocessable()->assertJsonValidationErrors('expiry');
    fmAction('share', ['path' => 'a.txt', 'expiry' => 30])->assertOk();

    expect(fmComponent(pageProps('/files-panel/files'))['share'])->toBe(['expiries' => [30]]);
});

it('ends shared links when sharing is turned off or the file is gone', function (): void {
    fmPlugin()->publicLinks(false);
    Storage::disk('public')->put('a.txt', 'a');
    $url = fmAction('share', ['path' => 'a.txt', 'expiry' => 60])->json('result.url');

    Storage::disk('public')->delete('a.txt');
    fmVisit($url)->assertNotFound();

    Storage::disk('public')->put('a.txt', 'a');
    fmPlugin()->shareLinks(false);
    fmVisit($url)->assertNotFound();
    fmAction('share', ['path' => 'a.txt', 'expiry' => 60])->assertForbidden();
});

it('stores public links that can be listed and revoked', function (): void {
    Storage::disk('public')->put('docs/a.pdf', '%PDF-1.4 a');

    $result = fmAction('share', ['path' => 'docs/a.pdf', 'expiry' => null])->assertOk()->json('result');
    $timed = fmAction('share', ['path' => 'docs/a.pdf', 'expiry' => 1440])->assertOk()->json('result');

    expect($result['url'])->toMatch('#/alfakit-files/link/[A-Za-z0-9]{48}$#')
        ->and($result['link']['expiresAt'] ?? null)->toBeNull()
        // A new link has its download count (the column default) right away.
        ->and($result['link']['downloads'] ?? null)->toBe(0)
        ->and($timed['link']['expiresAt'])->not->toBeNull();

    $link = FileManagerLink::query()->firstOrFail();
    expect($link->token)->not->toContain(substr((string) $result['url'], -48))
        ->and($link->token_hash)->not->toBe(substr((string) $result['url'], -48));

    auth()->logout();
    expect(fmBody(fmVisit($result['url'])->assertOk()))->toBe('%PDF-1.4 a');
    expect($link->fresh()?->downloads)->toBe(1);

    actingAs(fmUser());
    $links = fmAction('links', ['path' => 'docs/a.pdf'])->assertOk()->json('result.links');
    expect($links)->toHaveCount(2)->and($links[1]['url'])->toBe($result['url'])->and($links[1]['downloads'])->toBe(1);

    fmAction('revoke', ['id' => $result['link']['id']])->assertOk();

    auth()->logout();
    fmVisit($result['url'])->assertNotFound();
    get('/alfakit-files/link/'.str_repeat('a', 48))->assertNotFound();
});

it('expires public links', function (): void {
    Storage::disk('public')->put('a.txt', 'a');
    $url = fmAction('share', ['path' => 'a.txt', 'expiry' => 60])->json('result.url');

    $this->travel(2)->hours();

    fmVisit($url)->assertNotFound();
    expect(fmAction('links', ['path' => 'a.txt'])->json('result.links.0.expired'))->toBeTrue();
});

it('keeps public links when files are renamed or moved, and drops them when deleted', function (): void {
    Storage::disk('public')->put('docs/a.txt', 'a');
    Storage::disk('public')->makeDirectory('archive');
    $url = fmAction('share', ['path' => 'docs/a.txt', 'expiry' => null])->json('result.url');

    fmAction('rename', ['path' => 'docs/a.txt', 'name' => 'b.txt'])->assertOk();
    fmAction('move', ['paths' => ['docs'], 'destination' => 'archive'])->assertOk();

    expect(FileManagerLink::query()->value('path'))->toBe('archive/docs/b.txt');
    expect(fmBody(fmVisit($url)->assertOk()))->toBe('a');

    fmAction('delete', ['paths' => ['archive/docs']])->assertOk();

    expect(FileManagerLink::query()->count())->toBe(0);
    fmVisit($url)->assertNotFound();
});

it('revokes links of its own root only', function (): void {
    [$other] = FileManagerLink::issue(['panel' => 'files', 'disk' => 'public', 'root' => 'elsewhere', 'path' => 'a.txt']);

    fmAction('revoke', ['id' => $other->id])->assertNotFound();

    expect($other->fresh())->not->toBeNull();
});
