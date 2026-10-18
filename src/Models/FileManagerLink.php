<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * A stored public link to one file (`FileManagerPlugin::publicLinks()`): anyone with the URL
 * can download the file until the link expires or is revoked. The file is addressed by its
 * disk, the manager root it was shared from and its path under that root, so links follow
 * renames and moves and never reach outside the root.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $token
 * @property string $panel
 * @property string $disk
 * @property string $root
 * @property string $path
 * @property string|null $created_by
 * @property Carbon|null $expires_at
 * @property int $downloads
 * @property Carbon|null $last_accessed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FileManagerLink extends Model
{
    protected $table = 'alfakit_file_manager_links';

    protected $guarded = [];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'downloads' => 'integer',
        ];
    }

    /**
     * Create a link and return it with its plain token (only known at this moment).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: static, 1: string}
     */
    public static function issue(array $attributes): array
    {
        $token = Str::random(48);

        $link = new static;
        $link->fill([...$attributes, 'token_hash' => self::hash($token), 'token' => Crypt::encryptString($token)]);
        $link->save();

        return [$link, $token];
    }

    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public static function findByToken(string $token): ?static
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }

        /** @var static|null */
        return static::query()->where('token_hash', self::hash($token))->first();
    }

    public function plainToken(): ?string
    {
        try {
            return Crypt::decryptString($this->token);
        } catch (Throwable) {
            return null;
        }
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForRoot(Builder $query, string $panel, string $disk, string $root): Builder
    {
        return $query->where('panel', $panel)->where('disk', $disk)->where('root', $root);
    }
}
