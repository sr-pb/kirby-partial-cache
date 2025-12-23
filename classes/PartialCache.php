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
    private $needsUpdate;

    /**
     * Timestamp of cache item
     */
    private $lastModified;

    /**
     * Index with timestamps
     */
    private $index;

    public function __construct(string $key)
    {
        $this->cache = kirby()->cache('sr.partial-cache.files');

        if ($key) {

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

            $this->cacheItem = kirby()->cache('sr.partial-cache.files')->get($this->key);

            $this->lastModified = $this->cache->modified($this->key);
            $this->needsUpdate = false;
        }

        if (option('sr.partial-cache.cache') == false) {
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
    public function serialize($value)
    {
        if (! $value) {
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
    private $expires;

    /**
     * @param $expires
     *
     * @return Object
     */
    public function expires(int $expires = 0)
    {
        $this->expires = intval($expires);
        return $this;
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

            if ($data != null) {
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
    public function watch(array $watchOptions = [])
    {
        $this->index = kirby()->cache('sr.partial-cache')->get('index');

        if ($this->index === null) {
            Index::createIndex();
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
