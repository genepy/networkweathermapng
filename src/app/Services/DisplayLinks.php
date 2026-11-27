<?php
/**
 * Display links (F81): a unique address per map, /display/<token>, that a
 * wall screen or another tool (Centreon, a dashboard...) opens without
 * signing in. The token gives read access to that map only, and only to
 * what its kiosk page needs (DisplayController).
 *
 * The token is 192 random bits, shown once when the link is made; only its
 * SHA-256 is kept. A link has a label, an optional expiry date, can be
 * revoked, and remembers when it was last used. Each link is limited to
 * RATE_LIMIT requests per RATE_WINDOW seconds.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class DisplayLinks
{
    /** Requests a link may make in one window: a screen refreshes a few times a minute */
    public const RATE_LIMIT = 600;
    public const RATE_WINDOW = 300;
    /** last_used_at is written at most once a minute */
    private const TOUCH_EVERY = 60;
    public const LABEL_LENGTH = 100;

    public const OK = 'ok';
    public const UNKNOWN = 'unknown';
    public const LIMITED = 'limited';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Makes a link for a map; returns the link and its token, which is
     * never shown again.
     *
     * @return array{link: array<string, mixed>, token: string}
     */
    public function create(int $mapId, string $label, ?int $expiresAt, ?int $userId, ?int $now = null): array
    {
        $now ??= time();
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new \InvalidArgumentException(__('The expiry date is already past'));
        }
        $token = self::newToken();
        $id = $this->db->insert('display_links', [
            'map_id' => $mapId,
            'token_hash' => self::hash($token),
            'label' => mb_substr(trim(preg_replace('/\s+/u', ' ', $label) ?? ''), 0, self::LABEL_LENGTH),
            'expires_at' => $expiresAt,
            'created_by' => $userId,
            'created_at' => $now,
        ]);
        error_log(sprintf('Display link %d made for map %d by user %d', $id, $mapId, (int) $userId));

        return ['link' => self::publicLink((array) $this->db->queryOne('SELECT * FROM display_links WHERE id = ?', [$id]), $now), 'token' => $token];
    }

    /**
     * The links of a map, newest first, without their tokens
     *
     * @return list<array<string, mixed>>
     */
    public function forMap(int $mapId, ?int $now = null): array
    {
        $now ??= time();
        $rows = $this->db->query('SELECT * FROM display_links WHERE map_id = ? ORDER BY created_at DESC, id DESC', [$mapId]);
        return array_values(array_map(static fn (array $r) => self::publicLink($r, $now), $rows));
    }

    /** Revokes a link of a map; false when the map has no such link */
    public function revoke(int $mapId, int $linkId): bool
    {
        $done = $this->db->execute('UPDATE display_links SET revoked = 1 WHERE id = ? AND map_id = ?', [$linkId, $mapId]) > 0;
        if ($done) {
            error_log(sprintf('Display link %d of map %d revoked', $linkId, $mapId));
        }
        return $done;
    }

    /** Drops the links of a deleted map */
    public function forgetMap(int $mapId): void
    {
        $this->db->execute('DELETE FROM display_links WHERE map_id = ?', [$mapId]);
    }

    /**
     * The map a token opens: [state, map id]. The state is OK, UNKNOWN (no
     * such token, revoked or expired) or LIMITED (too many requests). Each
     * call counts as one request of the link.
     *
     * @return array{0: string, 1: int}
     */
    public function resolve(string $token, ?int $now = null): array
    {
        $now ??= time();
        if (!preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token)) {
            return [self::UNKNOWN, 0];
        }
        $row = $this->db->queryOne('SELECT * FROM display_links WHERE token_hash = ?', [self::hash($token)]);
        if ($row === null || (int) $row['revoked'] === 1 || ($row['expires_at'] !== null && (int) $row['expires_at'] <= $now)) {
            return [self::UNKNOWN, 0];
        }
        $id = (int) $row['id'];
        $start = (int) $row['window_start'];
        $count = (int) $row['window_count'];
        if ($now - $start >= self::RATE_WINDOW) {
            $start = $now;
            $count = 0;
        }
        $count++;
        $touch = $row['last_used_at'] === null || $now - (int) $row['last_used_at'] >= self::TOUCH_EVERY;
        $this->db->execute(
            'UPDATE display_links SET window_start = ?, window_count = ?' . ($touch ? ', last_used_at = ?' : '') . ' WHERE id = ?',
            $touch ? [$start, $count, $now, $id] : [$start, $count, $id]
        );
        if ($count > self::RATE_LIMIT) {
            if ($count === self::RATE_LIMIT + 1) {
                error_log(sprintf('Display link %d of map %d: too many requests, refused for a while', $id, (int) $row['map_id']));
            }
            return [self::LIMITED, 0];
        }
        return [self::OK, (int) $row['map_id']];
    }

    /** A new token: 192 random bits, URL-safe base64 (32 characters) */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The address of a token on this site */
    public static function path(string $token): string
    {
        return '/display/' . $token;
    }

    /**
     * A link as the Map panel lists it
     *
     * @param array<string, mixed> $row
     * @return array{id: int, label: string, created_at: int, expires_at: int|null, last_used_at: int|null, state: string}
     */
    private static function publicLink(array $row, int $now): array
    {
        $expires = $row['expires_at'] === null ? null : (int) $row['expires_at'];
        $state = (int) ($row['revoked'] ?? 0) === 1 ? 'revoked' : ($expires !== null && $expires <= $now ? 'expired' : 'active');
        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'created_at' => (int) $row['created_at'],
            'expires_at' => $expires,
            'last_used_at' => $row['last_used_at'] === null ? null : (int) $row['last_used_at'],
            'state' => $state,
        ];
    }
}
