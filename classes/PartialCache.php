<?php

declare(strict_types=1);

namespace Sr;

use Kirby\Filesystem\F;
use Kirby\Template\Snippet;
use Kirby\Template\Template;

use Exception;

use Sr\Index;

final class PartialCache
{
    /**
     * Cache
     */
    private \Kirby\Cache\Cache $cache;

    /**
     * @var string
     */
    private $key;

    /**
     * Cache item
     */
    private mixed $cacheItem;

    /**
     * Sets expiry date
     * https://getkirby.com/docs/reference/objects/cache/cache/set
     *
     * @var int
     */
    private int $expires = 0;


    /**
     * If cache needs update
     *
     * @var bool
     */
    private bool $needsUpdate = false;

    /**
     * Timestamp of cache item
     */
    private int $lastModified = 0;


    /**
     * Index with timestamps
     */
    private ?array $index = null;

    public function __construct(string $key)
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Cache key must not be empty');
        }

        $this->cache = kirby()->cache('sr.partial-cache.data');

        $prefix = '';

        if (kirby()->multilang()) {
            $prefix = kirby()->language()->code() . '/';
        }

        $this->key = $prefix . $key;

        /*
        * Evtl mit options?
        * expires, off, etc.
        * https://github.com/bnomei/kirby3-lapse/blob/master/classes/Lapse.php
        */

        $this->cacheItem = $this->cache->get($this->key);

        $this->lastModified = (int)($this->cache->modified($this->key) ?? 0);
        $this->needsUpdate = false;

        if (option('sr.partial-cache.enabled') === false) {
            $this->needsUpdate = true;
        }
    }

    /**
     * if value is a callback
     * https://github.com/bnomei/kirby3-lapse/blob/master/classes/Lapse.php
     */
    private static function isCallable($value): bool
    {
        // do not call global helpers just methods or closures
        return ! is_string($value) && is_callable($value);
    }

    /**
     * Serialize data
     *
     * Resolve content fields
     * https://github.com/bnomei/kirby3-lapse/blob/master/classes/Lapse.php
     *
     * @param $value
     *
     * @return mixed
     */
    private function serialize($value)
    {
        if ($value === null) {
            return null;
        }

        $value = self::isCallable($value) ? $value() : $value;

        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[$key] = $this->serialize($item);
            }
            return $items;
        }

        if (is_a($value, 'Kirby\Content\Field')) {
            return $value->value();
        }

        return $value;
    }


    /**
     * @deprecated deprecated Use expiresMinutes(), expiresHours() or expiresDays() instead
     * @param int $minutes
     */
    public function expires(int $minutes = 0): self
    {
        return $this->expiresMinutes($minutes);
    }

    /**
     * @param int $minutes
     */
    public function expiresMinutes(int $minutes = 0): self
    {
        $this->expires = max(0, $minutes);

        return $this;
    }

    /**
     * @param int $hours
     */
    public function expiresHours(int $hours = 0): self
    {
        $this->expires = max(0, $hours) * 60;

        return $this;
    }

    /**
     * @param int $days
     */
    public function expiresDays(int $days = 0): self
    {
        $this->expires = max(0, $days) * 60 * 24;

        return $this;
    }


    /**
     * Invalidates cache daily at a given time
     *
     * @param string|array<string> $time    e.g. '20:00' or ['17:15', '8pm']
     * @param \DateTimeZone|null $timezone
     */
    public function dailyAt(
        string|array $time,
        \DateTimeZone|null $timezone = null
    ): self {
        if ($this->cacheItem === null) {
            $this->needsUpdate = true;
            return $this;
        }

        $now = new \DateTimeImmutable('now', $timezone);

        if (!is_array($time)) {
            $time = [$time];
        }

        $slots = $this->getTimeSlots($time, $now, $timezone);

        // Find the most recent slot that is <= now (the "latest due time today")
        $latestPassed = $this->latestPassed($slots, $now);

        // Not time yet for any slot today
        if ($latestPassed === null) {
            return $this;
        }

        // Already ran since the latest passed slot
        if ($this->lastModified >= $latestPassed->getTimestamp()) {
            return $this;
        }

        $this->needsUpdate = true;

        return $this;
    }


    /**
     * @param array<string|int, string> $schedule
     * ```
     * [
     *     'Monday' => '10:15',
     *     'sun'.   => '8pm',
     *     3        => '14:00', // ISO weekday (1=Mon ... 7=Sun)
     * ]
     *  ```
     * @param \DateTimeZone|null $timezone
     */
    public function weeklyAt(
        array $schedule,
        \DateTimeZone|null $timezone = null
    ): self {
        if ($this->cacheItem === null) {
            $this->needsUpdate = true;
            return $this;
        }

        $now = new \DateTimeImmutable('now', $timezone);

        $slots = $this->getSchedule($schedule, $now, $timezone);

        // Find latest slot <= now
        $latestPassed = $this->latestPassed($slots, $now);

        // Not time yet for any slot this week
        if ($latestPassed === null) {
            return $this;
        }

        // Already ran since the latest passed slot
        if ($this->lastModified >= $latestPassed->getTimestamp()) {
            return $this;
        }

        $this->needsUpdate = true;

        return $this;
    }

    /**
     * Find latest slot <= now
     *
     * @param array<int, \DateTimeImmutable> $slots  Ascending-sorted slots
     * @return \DateTimeImmutable|null  The most recent passed slot, or null if none passed yet
     */
    private function latestPassed(array $slots, \DateTimeImmutable $now): \DateTimeImmutable|null
    {
        // Find latest slot <= now
        $latestPassed = null;

        foreach ($slots as $slot) {
            if ($slot <= $now) {
                $latestPassed = $slot;
            } else {
                break;
            }
        }

        return $latestPassed;
    }


    /**
     * Build the candidate time slots for the daily schedule.
     *
     * For every given time string two slots are generated: one for today and
     * one for yesterday. Including yesterday ensures that a cache which has not
     * been read for more than a day still detects a boundary that was passed
     * on the previous day (before today's slot is due).
     *
     * @param array<int, string>  $time      e.g. ['12:00', '8pm']
     * @param \DateTimeImmutable   $now
     * @param \DateTimeZone|null   $timezone
     *
     * @return array<int, \DateTimeImmutable>  Ascending-sorted slots (today and yesterday)
     *
     * @throws \InvalidArgumentException  If a time entry is not a non-empty parsable string
     */
    private function getTimeSlots(
        array $time,
        \DateTimeImmutable $now,
        \DateTimeZone|null $timezone = null
    ): array {
        $yesterday = $now->modify('-1 day');

        $slots = [];

        foreach ($time as $t) {
            if (!is_string($t) || trim($t) === '') {
                throw new \InvalidArgumentException('Invalid time string: ' . var_export($t, true));
            }

            try {
                // parse time (supports '8pm', '20:00', etc.)
                $runTime = new \DateTimeImmutable($t, $timezone);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("Invalid time string: {$t}", 0, $e);
            }

            $hour   = (int) $runTime->format('H');
            $minute = (int) $runTime->format('i');

            // today's slot and yesterday's slot at the same HH:MM
            $slots[] = $now->setTime($hour, $minute, 0);
            $slots[] = $yesterday->setTime($hour, $minute, 0);
        }

        // Sort ascending
        usort($slots, fn(\DateTimeImmutable $a, \DateTimeImmutable $b): int => $a <=> $b);

        return $slots;
    }

    /**
     * Build the candidate slots for the weekly schedule.
     *
     * For every weekday/time pair two slots are generated: one for the current
     * ISO week and one for the previous week. Including last week ensures that a
     * cache which has not been read for several days still detects a boundary
     * that was passed in the previous week (before this week's slot is due).
     *
     * @param array<string|int, string> $schedule
     * ```
     * [
     *     'Monday' => '10:15',
     *     'sun'    => '8pm',
     *     3        => '14:00', // ISO weekday (1=Mon ... 7=Sun)
     * ]
     * ```
     * @param \DateTimeImmutable   $now
     * @param \DateTimeZone|null   $timezone
     *
     * @return array<int, \DateTimeImmutable>  Ascending-sorted slots (this week and last week)
     *
     * @throws \InvalidArgumentException  On duplicate weekdays, invalid weekday keys or invalid time strings
     */
    private function getSchedule(
        array $schedule,
        \DateTimeImmutable $now,
        \DateTimeZone|null $timezone = null
    ): array {
        // Map weekday strings to ISO weekday numbers (1..7)
        $map = [
            'mon' => 1,
            'monday' => 1,
            'tue' => 2,
            'tues' => 2,
            'tuesday' => 2,
            'wed' => 3,
            'wednesday' => 3,
            'thu' => 4,
            'thur' => 4,
            'thurs' => 4,
            'thursday' => 4,
            'fri' => 5,
            'friday' => 5,
            'sat' => 6,
            'saturday' => 6,
            'sun' => 7,
            'sunday' => 7,
        ];

        // Start of "this week" in local time (Monday 00:00, ISO week)
        $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);

        $slots = [];
        $seen  = [];

        foreach ($schedule as $dayKey => $time) {

            $key = mb_strtolower(trim((string) $dayKey), 'UTF-8');

            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("weeklyAt(): duplicate weekday '{$dayKey}' after normalization");
            }

            $seen[$key] = true;

            // Validate time
            if (!is_string($time) || trim($time) === '') {
                throw new \InvalidArgumentException("weeklyAt(): invalid time for '{$dayKey}'");
            }

            // Normalize weekday key -> ISO int 1..7
            if (is_numeric($key)) {
                if (!preg_match('/^\d+$/', $key)) {
                    throw new \InvalidArgumentException("weeklyAt(): weekday must be an integer 1..7, got '{$dayKey}'");
                }

                $dow = (int) $key;

                if ($dow < 1 || $dow > 7) {
                    throw new \InvalidArgumentException("weeklyAt(): weekday int must be 1..7, got {$dow}");
                }
            } else {
                if (!isset($map[$key])) {
                    throw new \InvalidArgumentException("weeklyAt(): invalid weekday '{$dayKey}'");
                }

                $dow = $map[$key];
            }

            // Parse time string (supports "8pm", "10:15", etc.)
            try {
                $t = new \DateTimeImmutable($time, $timezone);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("weeklyAt(): invalid time string '{$time}' for '{$dayKey}'", 0, $e);
            }

            $hour   = (int) $t->format('H');
            $minute = (int) $t->format('i');

            // Slot for this week: weekStart + (dow-1) days at HH:MM
            $slots[] = $weekStart
                ->modify('+' . ($dow - 1) . ' days')
                ->setTime($hour, $minute, 0);

            // Same slot one week earlier
            $slots[] = $weekStart
                ->modify('-1 week')
                ->modify('+' . ($dow - 1) . ' days')
                ->setTime($hour, $minute, 0);
        }

        // Sort slots ascending
        usort($slots, fn(\DateTimeImmutable $a, \DateTimeImmutable $b): int => $a <=> $b);

        return $slots;
    }

    /**
     * Removes all cache files created by this plugin
     * https://github.com/bnomei/kirby3-lapse/blob/master/classes/Lapse.php
     *
     * @return bool
     */
    public function flush(): bool
    {
        $success = false;

        try {
            $success = $this->cache->flush();
        } catch (Exception $e) {
        }

        return $success;
    }

    /**
     * Data method
     * Set data in cache or return cached file
     */
    public function data($data = null)
    {
        if ($data === null) {
            return null;
        }

        if ($this->cacheItem === null || $this->needsUpdate) {
            $this->cacheItem = $this->serialize($data);

            $this->cache->set($this->key, $this->cacheItem, $this->expires);
            return $this->cacheItem;
        }

        return $this->cacheItem;
    }

    /**
     * Snippet method
     */
    public function snippet($snippet, $data = null)
    {
        if ($this->cacheItem === null || $this->needsUpdate) {
            $snippetData = [];

            if ($data !== null) {
                $snippetData = $this->serialize($data);
            }

            $s = snippet($snippet, $snippetData, true);
            $this->cacheItem = $s;

            $this->cache->set($this->key, $this->cacheItem, $this->expires);

            return $this->cacheItem;
        }

        return $this->cacheItem;
    }

    /**
     * Check page timestamps
     * - page id
     * - page uuid
     * - page blueprint
     */
    private function checkPages($option): void
    {
        if ($this->needsUpdate) {
            return;
        }

        if (!is_array($option)) {
            return;
        }

        foreach ($option as $key => $value) {
            foreach ($value as $id) {
                $id = str_replace('page://', '', $id);

                $expires = $this->index['pages'][$key][$id] ?? null;

                if ($expires && $this->lastModified < $expires) {
                    $this->needsUpdate = true;
                }
            }
        }
    }

    /**
     * Check collections timestamps
     */
    private function checkCollections($option): void
    {
        if (!is_array($option)) {
            return;
        }

        foreach ($option as $collection) {
            $index = $this->index['collections'][$collection] ?? null;

            if (
                ! $this->needsUpdate
                && $index
                && $this->lastModified < $index
            ) {
                $this->needsUpdate = true;
            }
        }
    }


    /**
     * Check site modified timestamp
     */
    private function checkSiteModified(): void
    {
        if (
            isset($this->index['site.modified'])
            && $this->lastModified < $this->index['site.modified']
        ) {
            $this->needsUpdate = true;
        }
    }

    /**
     * Check file timestamps
     * - snippets
     */
    private function checkSnippets(array $items): void
    {
        foreach ($items as $item) {
            if (! $this->needsUpdate) {
                $file = Snippet::file($item);

                if ($file) {
                    $fileTime = F::modified($file);

                    if ($this->lastModified < $fileTime) {
                        $this->needsUpdate = true;
                    }
                }
            }
        }
    }

    /**
     * Checks file timestamps
     * - templates
     */
    private function checkTemplates(array $items): void
    {
        foreach ($items as $item) {
            if (! $this->needsUpdate) {
                $f = new Template($item);
                $file = $f->file() ?? null;

                if ($file) {
                    $fileTime = F::modified($file);

                    if ($this->lastModified < $fileTime) {
                        $this->needsUpdate = true;
                    }
                }
            }
        }
    }


    /**
     * @param array $entries
     */
    public function checkExpiresAt(array $entries): void
    {
        $entries = $this->normalizeArray($entries);

        foreach ($entries as $key => $callback) {
            if ($callback === null) {
                continue;
            }

            $expiresAt = $this->index['expiresAt'][$key] ?? null;

            // Missing expiry -> compute once
            if ($expiresAt === null) {
                $expiresAt = $this->updateCustomTimestamp($key, $callback);
            }

            // If already invalidated by other rules, recompute so the stored expiry
            // matches what the next cached output is based on.
            if ($this->needsUpdate) {
                $this->updateCustomTimestamp($key, $callback);
                continue;
            }

            // Expired -> invalidate and compute the next expiry
            if (time() > (int)$expiresAt) {
                $this->needsUpdate = true;
                $this->updateCustomTimestamp($key, $callback);
            }
        }
    }

    private function updateCustomTimestamp(string $key, callable $callback) {
        $expiresAt = $this->evaluateExpiryTimestamp($key, $callback);
        $this->assertTimestamp($key, $expiresAt);

        $this->index['expiresAt'][$key] = $expiresAt;
        kirby()->cache('sr.partial-cache')->set('index', $this->index);

        return $expiresAt;
    }

    private function evaluateExpiryTimestamp(string $key, callable $callback): int
    {
        $value = $callback();

        // DateTime / DateTimeImmutable
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        // Strict int
        if (is_int($value)) {
            return $value;
        }

        // Optional: allow numeric string
        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            return (int)$value;
        }

        throw new \InvalidArgumentException(
            "expiresAt: callback for '{$key}' must return int unix timestamp or DateTimeInterface; got "
            . gettype($value) . " (" . var_export($value, true) . ")"
        );
    }

    private function assertTimestamp(string $key, int $ts): void
    {
        // reject negative / absurdly small/large values
        if ($ts <= 0) {
            throw new \InvalidArgumentException("expiresAt: invalid timestamp for '{$key}': {$ts}");
        }
    }

    private function normalizeArray(array $array): array
    {
        $rules = [];

        foreach ($array as $key => $value) {

            // 'key'
            if (is_int($key)) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException(
                        "expiresAt: numeric entries must be strings"
                    );
                }

                $rules[$value] = null;
                continue;
            }

            // 'key' => fn() => ...
            if (is_string($key)) {
                if ($value !== null && !is_callable($value)) {
                    throw new \InvalidArgumentException(
                        "expiresAt: value for '{$key}' must be a callable or null"
                    );
                }

                $rules[$key] = $value;
                continue;
            }

            throw new \InvalidArgumentException("expiresAt: invalid entry");
        }

        return $rules;
    }


    private function getType($type, $option)
    {
        $map = [
            'pages' => function ($option) {
                return $this->checkPages($option);
            },
            'collections' => function ($option) {
                return $this->checkCollections($option);
            },
            'templates' => function ($option) {
                return $this->checkTemplates($option);
            },
            'snippets' => function ($option) {
                return $this->checkSnippets($option);
            },
            'site.modified' => function () {
                return $this->checkSiteModified();
            },
            'expiresAt' => function ($option) {
                return $this->checkExpiresAt($option);
            },
        ];

        if (!isset($map[$type])) {
            return null;
        }

        return $map[$type]($option);
    }

    private $checkingOrder = [
        'pages',
        'site.modified',
        'collections',
        'templates',
        'snippets',
        'expiresAt',
    ];

    /**
     * Watch timestamps
     *
     * @param array $watchOptions   Array with options.
     */
    public function watch(array $watchOptions = []): self
    {
        $this->index = kirby()->cache('sr.partial-cache')->get('index');

        if ($this->index === null) {
            Index::createIndex();
            return $this;
        }

        if (! empty($watchOptions) && is_array($watchOptions)) {

            /**
             * Sort watchOptions by least consuming
             *
             * sort by array template:
             * https://codereview.stackexchange.com/questions/282974/sorting-an-array-by-given-hierarchy
             */
            $watchOptions = array_merge(
                array_intersect_key(array_flip($this->checkingOrder), $watchOptions),
                $watchOptions
            );

            foreach ($watchOptions as $type => $option) {
                if (! $this->needsUpdate) {
                    $this->getType($type, $option);
                }
            }
        }

        return $this;
    }
}
