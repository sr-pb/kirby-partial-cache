<?php

declare(strict_types=1);

namespace Sr;

final class Index
{
    public static function indexPage($page): void
    {
        $index = kirby()->cache('sr.partial-cache')->get('index', []);
        $modified = $page->modified();
        $blueprint = $page->intendedTemplate()->name();

        $index['pages']['id'][$page->uuid()->id()] = $modified;
        $index['pages']['id'][$page->id()] = $modified;

        $blueprintIndex = $index['pages']['blueprint'][$blueprint] ?? null;

        if (! $blueprintIndex) {
            $index['pages']['blueprint'][$blueprint] = $modified;
        } elseif ($modified > $blueprintIndex) {
            $index['pages']['blueprint'][$blueprint] = $modified;
        }

        kirby()->cache('sr.partial-cache')->set('index', $index);
    }

    public static function indexCollection($collection): void
    {
        $index = kirby()->cache('sr.partial-cache')->get('index', []);
        $lastModifiedPage = kirby()->collection($collection)->sortBy('modified', 'desc')->limit(1)->first();
        $modified = $lastModifiedPage->modified();
        $collectionIndex = $index['collections'][$collection] ?? null;

        if (! $collectionIndex) {
            $index['collections'][$collection] = $modified;
        } elseif ($modified > $collectionIndex) {
            $index['collections'][$collection] = $modified;
        }

        kirby()->cache('sr.partial-cache')->set('index', $index);
    }

    public static function updatePage($page): void
    {
        $timestamp = time();

        $index = kirby()->cache('sr.partial-cache')->get('index', []);

        $index['pages']['blueprint'][$page->intendedTemplate()->name()] = $timestamp;
        $index['pages']['id'][$page->uuid()->id()] = $timestamp;
        $index['pages']['id'][$page->id()] = $timestamp;
        $index['pages']['all'] = $timestamp;
        $index['site.modified'] = $timestamp;

        $collections = option('sr.partial-cache.collections');

        if ($collections !== false) {
            // convert to array if $collection is string
            $collections = is_string($collections) ? [$collections] : $collections;

            $index = self::updateCollections($index, $collections, $page, $timestamp);
        }

        kirby()->cache('sr.partial-cache')->set('index', $index);
    }

    private static function updateCollections($index, $collections, $page, $timestamp)
    {
        foreach ($collections as $collection) {
            if (kirby()->collection($collection)->has($page)) {
                $index['collections'][$collection] = $timestamp;
            }
        }

        return $index;
    }

    public static function siteUpdate(): void
    {
        $timestamp = time();

        $index = kirby()->cache('sr.partial-cache')->get('index', []);
        $index['site.update'] = $timestamp;
        $index['site.modified'] = $timestamp;

        kirby()->cache('sr.partial-cache')->set('index', $index);
    }

    /**
     * Creates index of everything
     */
    public static function createIndex(): void
    {
        $allPages = site()->index();

        foreach ($allPages as $page) {
            self::indexPage($page);
        }

        self::siteUpdate();

        $collections = option('sr.partial-cache.collections');

        if (
            isset($collections)
            && $collections !== false
        ) {
            if (is_array($collections)) {
                foreach ($collections as $collection) {
                    self::indexCollection($collection);
                }
            }

            if (is_string($collections)) {
                self::indexCollection($collections);
            }
        }

        $index = kirby()->cache('sr.partial-cache')->get('index', []);
        $index['site.modified'] = site()->modified();
    }
}
