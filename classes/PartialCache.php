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
    private $cache;

    /**
     * @var string
     */
    private $key;

    /**
     * Cache item
     */
    private $cacheItem;

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
    private $index;

    public function __construct(string $key)
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Cache key must not be empty');
        }

        $this->cache = kirby()->cache('sr.partial-cache.files');

        $prefix = '';

        if (kirby()->multilang()) {
            $prefix = kirby()->language()->code() . '/';
        }

        $this->key = $prefix . $key;

        $this->expires = 0;

        /*
        * Evtl mit options?
        * expires, off, etc.
        * https://github.com/bnomei/kirby3-lapse/blob/master/classes/Lapse.php
        */

        $this->cacheItem = $this->cache->get($this->key);

        $this->lastModified = (int)($this->cache->modified($this->key) ?? 0);
        $this->needsUpdate = false;

        if (option('sr.partial-cache.cache') === false) {
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
     * Sets expiry date
     * https://getkirby.com/docs/reference/objects/cache/cache/set
     *
     * @var int
     */
    private int $expires = 0;

    /**
     * @param int $minutes
     */
    public function expires(int $minutes = 0): self
    {
        $this->expires = intval($minutes);

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
    ): self
    {
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
        \DateTimeZone|null $timezone = null): self
    {
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
     * @param array<int,\DateTimeImmutable> $slots
     */
    private function latestPassed(array $slots, \DateTimeImmutable $now): ?\DateTimeImmutable
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
     * Get time slots
     * @return array<int,\DateTimeImmutable>
     */
    private function getTimeSlots(
        array $time,
        \DateTimeImmutable $now,
        \DateTimeZone|null $timezone = null): array
    {
        // Build today's schedule as DateTimeImmutable instances
        $slots = [];

        foreach ($time as $t) {
            if (!is_string($t) || trim($t) === '') {
                throw new \InvalidArgumentException("Invalid time string: " . var_export($t, true));
            }

            try {
                // parse time (supports '8pm', '20:00', etc.)
                $runTime = new \DateTimeImmutable($t, $timezone);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("Invalid time string: {$t}", 0, $e);
            }

            $slot = $now->setTime(
                (int) $runTime->format('H'),
                (int) $runTime->format('i'),
                0
            );

            $slots[] = $slot;
        }

        // Sort ascending
        usort($slots, fn($a, $b) => $a <=> $b);

        return $slots;
    }

    /**
     * Get schedule
     * @return array<int,\DateTimeImmutable>
     */
    private function getSchedule(
        array $schedule,
        \DateTimeImmutable $now,
        \DateTimeZone|null $timezone = null): array
    {
        // Map weekday strings to ISO weekday numbers (1..7)
        $map = [
            'mon' => 1, 'monday' => 1,
            'tue' => 2, 'tues' => 2, 'tuesday' => 2,
            'wed' => 3, 'wednesday' => 3,
            'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4,
            'fri' => 5, 'friday' => 5,
            'sat' => 6, 'saturday' => 6,
            'sun' => 7, 'sunday' => 7,
        ];

        // Start of "this week" in local time (Monday 00:00, ISO week)
        $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);

        $slots = [];

        $seen = [];

        foreach ($schedule as $dayKey => $time) {

            $key = mb_strtolower(trim((string)$dayKey), 'UTF-8');

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
                    throw new \InvalidArgumentException(
                        "weeklyAt(): invalid weekday '{$dayKey}'"
                    );
                }

                $dow = $map[$key];
            }

            // Parse time string (supports "8pm", "10:15", etc.)
            try {
                $t = new \DateTimeImmutable($time, $timezone);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("weeklyAt(): invalid time string '{$time}' for '{$dayKey}'", 0, $e);
            }

            // Slot for this week: weekStart + (dow-1) days at HH:MM
            $slot = $weekStart
                ->modify('+' . ($dow - 1) . ' days')
                ->setTime(
                    (int)$t->format('H'),
                    (int)$t->format('i'),
                    0
                );

            $slots[] = $slot;
        }

        // Sort slots ascending
        usort($slots, fn(\DateTimeImmutable $a, \DateTimeImmutable $b) => $a <=> $b);

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
     * Check site.*:after timestamps
     */
    private function checkSiteUpdate($option): void
    {
        if (
            $option === true
            && isset($this->index['site.update'])
            && $this->lastModified < $this->index['site.update']
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
            'site.update' => function ($option) {
                return $this->checkSiteUpdate($option);
            },
            'site.modified' => function () {
                return $this->checkSiteModified();
            },
        ];

        if (!isset($map[$type])) {
            return null;
        }

        return $map[$type]($option);
    }

    private $checkingOrder = [
        'pages',
        'site.update',
        'site.modified',
        'collections',
        'templates',
        'snippets',
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
