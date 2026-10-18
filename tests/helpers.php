<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\AlfakitFileManager\FileManagerPlugin;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function inertiaGet(string $url): TestResponse
{
    return \Pest\Laravel\get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
}

/**
 * @return array<string, mixed>
 */
function pageProps(string $url): array
{
    $response = inertiaGet($url);
    $response->assertOk();

    return $response->json('props.page');
}

function fmUser(string $name = 'Ada Admin'): User
{
    return User::query()->create(['name' => $name, 'email' => uniqid('user', true).'@example.com', 'password' => 'password']);
}

function fmTenantUser(string $name = 'Ada Admin'): TenantUser
{
    return TenantUser::query()->create(['name' => $name, 'email' => uniqid('user', true).'@example.com', 'password' => 'password']);
}

function fmTeam(string $name): Team
{
    return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
}

function fmPlugin(string $panel = 'files'): FileManagerPlugin
{
    $plugin = alfakit()->getPanel($panel)->getPlugin(FileManagerPlugin::ID);

    return $plugin instanceof FileManagerPlugin ? $plugin : throw new RuntimeException('The file manager plugin is not registered.');
}

/**
 * The serialised `file-manager` component of a page.
 *
 * @param  array<string, mixed>  $page
 * @return array<string, mixed>
 */
function fmComponent(array $page): array
{
    foreach ($page['content'] ?? [] as $block) {
        foreach ($block['components'] ?? [] as $component) {
            if (($component['type'] ?? null) === 'file-manager') {
                return $component;
            }
        }
    }

    throw new RuntimeException('The page has no file manager.');
}

/**
 * The context token of the file manager page.
 */
function fmToken(string $prefix = '/files-panel'): string
{
    return (string) pageProps($prefix.'/files')['token'];
}

/**
 * Run a file manager action as the renderer's `executeJson()` does.
 *
 * @param  array<string, mixed>  $arguments
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function fmAction(string $action, array $arguments = [], ?string $token = null, string $prefix = '/files-panel'): TestResponse
{
    return \Pest\Laravel\postJson($prefix.'/_alfakit/actions', [
        'token' => $token ?? fmToken($prefix),
        'component' => 'file-manager',
        'action' => $action,
        'arguments' => ['disk' => 'public', ...$arguments],
    ]);
}

/**
 * Upload a file to the panel's temporary upload endpoint and return its key.
 */
function fmTemporaryUpload(UploadedFile $file, string $prefix = '/files-panel'): string
{
    $response = \Pest\Laravel\post($prefix.'/_alfakit/uploads', ['file' => $file], ['Accept' => 'application/json']);
    $response->assertOk();

    return (string) $response->json('key');
}

/**
 * A tiny valid PNG.
 */
function fmPng(string $name = 'photo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgwAIAABUAAbJuDnMAAAAASUVORK5CYII=', true));
}
