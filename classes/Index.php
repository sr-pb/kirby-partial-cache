<?php

declare(strict_types=1);

namespace Sr;

final class Index
{
    public static function indexPage(\Kirby\Cms\Page $page): void
    {
        $index = kirby()->cache('sr.partial-cache')->get('index', []);

        $index = self::pageData($index, $page);

        kirby()->cache('sr.partial-cache')->set('index', $index);
    }

    private static function pageData(array $index, \Kirby\Cms\Page $page): array
    {
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

        return $index;
    }

    public static function updatePage(\Kirby\Cms\Page $page): void
    {
        $timestamp = time();

        $index = kirby()->cache('sr.partial-cache')->get('index', []);

        $index['pages']['blueprint'][$page->intendedTemplate()->name()] = $timestamp;
        $index['pages']['id'][$page->uuid()->id()] = $timestamp;
        $index['pages']['id'][$page->id()] = $timestamp;
        $index['site.modified'] = $timestamp;

        kirby()->cache('sr.partial-cache')->set('index', $index);
    }

    public static function deletePage(\Kirby\Cms\Page $page): void
    {
        // Behaves like updatePage: sets a fresh timestamp so that
        // ID watchers invalidate. Stale IDs are removed on the
        // next createIndex() rebuild.
        self::updatePage($page);
    }

    public static function updateSite(): void
    {
        $index = kirby()->cache('sr.partial-cache')->get('index', []);
        $timestamp = time();

        $index['site.update'] = $timestamp;
        $index['site.modified'] = $timestamp;

        kirby()->cache('sr.partial-cache')->set('index', $index);
    }

    /**
     * Creates index of everything
     */
    public static function createIndex(): void
    {
        $index = [];
        $allPages = site()->index();

        foreach ($allPages as $page) {
            $index = self::pageData($index, $page);
        }

        $modified = site()->modified();

        $index['site.update'] = $modified;
        $index['site.modified'] = $modified;

        kirby()->cache('sr.partial-cache')->set('index', $index);
    }
}
