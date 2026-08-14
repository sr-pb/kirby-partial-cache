# kirby-partial-cache

A plugin to partially cache snippets or arbitrary data with lazy, timestamp-based invalidation.

## Installation

Download and copy this repository to `/site/plugins/partial-cache`.

The plugin is enabled per default. To deactivate it, add the following to your `config.php`:

```php
return [
    'sr.partial-cache' => [
        'enabled' => false,
    ]
]
```

## Quick example 

```php
<?php

// Cache the sidebar.
// The cache is invalidated when the watched page is edited.

$data = partialCache('sidebar')
    ->watch([
        'pages' => [
            'id' => [
                $page->id(),
            ],
        ],
    ])
    ->snippet('sidebar');

?>
<div id="sidebar">
<?= $data ?>
</div>
```

## Cache key

Cache keys must be unique. Keys can be organized in a folder-like structure, which makes per-page caching easy. On multilingual sites, the language code is automatically prepended.

```php
<?php

// Cache a snippet with the key 'related-articles'
$data = partialCache('related-articles')
    ->watch([
        // … options
    ])
    ->snippet('related-articles');


// …or cache the snippet per page, e.g.
// "articles/example-post/related-articles"
$data = partialCache($page->id() . '/related-articles')
    ->watch([
        // … options
    ])
    ->snippet('related-articles');
```

## Methods to cache data

Choose between `snippet()` and `data()`.

```php
<?php

// Cache a snippet
$data = partialCache('a-unique-cache-key')
    ->watch([
        // … options
    ])
    ->snippet('article', ['article' => $article]);


// Cache data in a closure
$data = partialCache('a-unique-cache-key')
    ->watch([
        // … options
    ])
    ->data(function () {
        $data = 'Something that should be cached.';

        return $data;
    });
```

## Watch options

>**Note:** Invalidation checks are evaluated lazily when the cache is accessed – no cron jobs are used.

You can invalidate the cache based on time schedules and change timestamps, for example:
- cache for a given number of minutes (same as `$cache->set()`)
- invalidate daily at specific times
- invalidate weekly at specific days
- when pages with specific blueprints or IDs/UUIDs are edited
- when the site is updated or modified
- when templates or snippets change

All invalidation rules are combined with **OR** logic – if any rule requires invalidation, the cache is refreshed.

```php
<?php

$data = partialCache('a-unique-cache-key')

    // Writes an item to the cache for a given number of minutes
    ->expiresMinutes(1)

    // Writes an item to the cache for a given number of hours
    ->expiresHours(2)

    // Writes an item to the cache for a given number of days
    ->expiresDays(3)

    // Invalidate daily at specific times (DateTime-compatible strings)
    ->dailyAt('12:00') // or ['1pm', '20:00']

    // Invalidate weekly at specific weekdays
    ->weeklyAt([
        'Monday' => '12:00',   // full name
        'Tue'    => '3pm',     // short name
        5        => '20:00'    // ISO weekday (1=Mon ... 7=Sun)
    ])

    // Watch for timestamp-based changes
    ->watch([

        // If anything has been edited (cached version of $site->modified())
        'site.modified' => true,

        // If the site has been updated (site.*:after hooks)
        'site.update' => true,

        // Watch pages
        'pages' => [

            // Watch by IDs or UUIDs
            'id' => [
                $page->uuid()->id(),    // UUID
                'some/page/id',        // page ID
            ],

            // Watch by blueprint
            'blueprint' => [
                'home',
                'event',
            ],
        ],

        // Watch snippets
        'snippets' => [
            'header',
            'footer',
        ],

        // Watch templates
        'templates' => [
            'default',
            'post',
        ],
    ])
    ->snippet('path/to/snippet');

echo $data;
```

## Examples
```php
<?php

// Writes an item to the cache for a given number of minutes.
// Same as $cache->set()
// https://getkirby.com/docs/reference/objects/cache/cache/set

$expires = partialCache('cache-for-five-minutes')
    ->expiresMinutes(5)
    ->snippet('some/snippet');

echo $expires;


// Cache a snippet. Invalidates if:
// - invalidated daily at 00:10
// - a page with the given ID has been edited
// - a page with blueprint "home" has been edited
// - a page with blueprint "event" has been edited
// - the snippet "event-detail.php" has been edited

$event = partialCache('events/' . $event->id())
    ->dailyAt('00:10')
    ->watch([
        'pages' => [
            'id' => [
                $event->id(),
            ],
            'blueprint' => [
                'home',
                'event',
            ],
        ],
        'snippets' => [
            'event-detail',
        ],
    ])
    ->snippet('event-detail', ['event' => $event]);

echo $event;


// Cache data. Invalidates if:
// - invalidated weekly on Saturday at 20:00
// - a page with blueprint "post" has been edited
// - the template "blog.json.php" has been edited

$posts = partialCache('my-api/posts')
    ->weeklyAt([
        'Saturday' => '20:00'
    ])
    ->watch([
        'pages' => [
            'blueprint' => [
                'post',
            ],
        ],
        'templates' => [
            'blog.json',
        ],
    ])
    ->data(function () {
        $posts = kirby()->collection('posts');

        $array = [];

        foreach ($posts as $post) {
            $array[] = [
                'slug' => $post->slug(),
                'title' => $post->title(),
                'url' => $post->url(),
            ];
        }

        return json_encode($array);
    });

echo $posts;
```

## Panel buttons

The plugin provides two Panel buttons for maintenance tasks:

- **Flush cache** – clears the partial cache  
- **Build site index** – rebuilds the site index  

These buttons are useful if you want to manually flush the cache or rebuild the site index without touching the filesystem.

Example configuration in `site.yml`:

```yaml
# site.yml

fields:
  flush_cache:
    type: cachebutton
    label: Flush cache
    text: Flush
    cache: sr.partial-cache

  index_site:
    type: indexbutton
    label: Build site index
```

## Credits

- [Kirby Lapse](https://github.com/bnomei/kirby3-lapse)
- [Kirby Boost](https://github.com/bnomei/kirby3-boost).

## License
MIT
