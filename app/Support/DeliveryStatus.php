<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Runtime, admin-controlled delivery-status notice broadcast to vendors.
 * Backed by the `settings` table so an admin can post or update a notice
 * (e.g. "deliveries are slow right now") instantly without a deploy.
 *
 * Vendors see the active notice as a modal on their main dashboard and can
 * dismiss it. Dismissal is tracked client-side (localStorage) keyed by the
 * `version` timestamp below, so editing the notice re-shows it to everyone.
 */
class DeliveryStatus
{
    /** Settings group used for every key written by this feature. */
    public const GROUP = 'delivery_status';

    public const ACTIVE_KEY = 'delivery_status.active';

    public const LEVEL_KEY = 'delivery_status.level';

    public const MESSAGE_KEY = 'delivery_status.message';

    /** Bumped whenever the notice changes; used as the client dismissal key. */
    public const VERSION_KEY = 'delivery_status.version';

    /** Supported delivery-status levels (drives the vendor UI colour + icon). */
    public const LEVELS = ['fast', 'normal', 'slow'];

    public const DEFAULT_LEVEL = 'normal';

    public static function isActive(): bool
    {
        return Setting::get(self::ACTIVE_KEY, '0') === '1';
    }

    public static function level(): string
    {
        $level = Setting::get(self::LEVEL_KEY, self::DEFAULT_LEVEL);

        return in_array($level, self::LEVELS, true) ? $level : self::DEFAULT_LEVEL;
    }

    public static function message(): string
    {
        $message = Setting::get(self::MESSAGE_KEY);

        return is_string($message) ? trim($message) : '';
    }

    /**
     * Version token identifying the current notice. Changes on every update so
     * a dismissed notice reappears once the admin edits it.
     */
    public static function version(): string
    {
        return (string) Setting::get(self::VERSION_KEY, '');
    }

    /**
     * The active notice for the vendor dashboard, or null when nothing should
     * be shown (inactive or empty message).
     *
     * @return array{level:string,message:string,version:string}|null
     */
    public static function current(): ?array
    {
        if (! self::isActive()) {
            return null;
        }

        $message = self::message();

        if ($message === '') {
            return null;
        }

        return [
            'level' => self::level(),
            'message' => $message,
            'version' => self::version(),
        ];
    }

    /**
     * Persist a notice from the admin form. Always bumps the version so the
     * refreshed notice is shown again to vendors who dismissed a prior one.
     */
    public static function update(bool $active, string $level, ?string $message): void
    {
        $level = in_array($level, self::LEVELS, true) ? $level : self::DEFAULT_LEVEL;

        Setting::set(self::ACTIVE_KEY, $active ? '1' : '0', self::GROUP);
        Setting::set(self::LEVEL_KEY, $level, self::GROUP);
        Setting::set(self::MESSAGE_KEY, (string) ($message ?? ''), self::GROUP);
        Setting::set(self::VERSION_KEY, (string) now()->timestamp, self::GROUP);
    }
}
