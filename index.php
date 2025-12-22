<?php

declare(strict_types=1);

use Kirby\Cms\App as Kirby;
use Kirby\Cms\Page;
use Kirby\Cms\File;
use Kirby\Cms\Site;

@include_once __DIR__ . '/vendor/autoload.php';

use Sr\Index;
use Sr\PartialCache;

if (! function_exists('partialCache')) {
    function partialCache(string $key)
    {
        return new PartialCache($key);
    }
}

Kirby::plugin('sr/partial-cache', [
    'options' => [
        'cache' => true,
        'cache.files' => true,
        'collections' => false,
    ],
    'fields' => [
        'cachebutton' => [
            'props' => [
                'label' => function ($label = 'Flush cache') {
                    return $label;
                },
                'text' => function ($text = null) {
                    return $text;
                },
                'cache' => function ($cache) {
                    return $cache;
                },
            ],
        ],
        'indexbutton' => [
            'props' => [
                'label' => function ($label) {
                    if ($label === 'IndexButton') {
                        return 'Build site index';
                    }
                    return $label;
                },
                'text' => function ($text = null) {
                    return $text;
                },
            ],
        ],
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'c/clear/(:any)',
                'action' => function ($instance) {
                    $success = false;

                    if ($instance) {
                        try {
                            $success = kirby()->cache($instance)->flush();
                        } catch (Exception $e) {
                        }
                    }

                    return $success;
                },
            ],
            [
                'pattern' => 'c/index',
                'action' => function () {

                    Index::createIndex();

                    return [
                        'count' => site()->index()->count(),
                    ];
                },
            ],
        ],
    ],
    'hooks' => [
        /**
         * Page hooks
         */
        'page.create:after' => function (Page $page): void {
            Index::updatePage($page);
        },
        'page.delete:after' => function (Page $page): void {
            // todo: page delete => aus index entfernen
            Index::updatePage($page);
        },
        'page.duplicate:after' => function (Page $duplicatePage): void {
            Index::updatePage($duplicatePage);
        },
        /**
         * Page update hooks
         */
        'page.update:after' => function (Page $newPage): void {
            Index::updatePage($newPage);
        },
        'page.changeNum:after' => function (Page $newPage): void {
            Index::updatePage($newPage);
        },
        'page.changeStatus:after' => function (Page $newPage): void {
            Index::updatePage($newPage);
        },
        'page.changeSlug:after' => function (Page $newPage): void {
            Index::updatePage($newPage);
        },
        'page.changeTitle:after' => function (Page $newPage): void {
            Index::updatePage($newPage);
        },
        /**
         * Site hooks
         */
        'site.update:after' => function (Site $newSite): void {
            Index::siteUpdate($newSite);
        },
        'site.changeTitle:after' => function (Site $newSite): void {
            Index::siteUpdate($newSite);
        },
        /**
         * File hooks
         */
        'file.create:after' => function (File $file): void {
            if ($file->page()) {
                Index::updatePage($file->page());
            }
        },
        'file.delete:after' => function (File $file): void {
            if ($file->page()) {
                Index::updatePage($file->page());
            }
        },
        'file.update:after' => function (File $newFile): void {
            if ($newFile->page()) {
                Index::updatePage($newFile->page());
            }
        },
        'file.changeName:after' => function (File $newFile): void {
            if ($newFile->page()) {
                Index::updatePage($newFile->page());
            }
        },
        'file.changeSort:after' => function (File $newFile): void {
            if ($newFile->page()) {
                Index::updatePage($newFile->page());
            }
        },
        'file.replace:after' => function (File $newFile): void {
            if ($newFile->page()) {
                Index::updatePage($newFile->page());
            }
        },
    ],
]);
