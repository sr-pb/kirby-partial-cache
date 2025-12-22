# kirby-partial-cache

A plugin to partially cache any data.<br>
Heavily inspired by [Kirby Lapse](https://github.com/bnomei/kirby3-lapse) and [Kirby Boost](https://github.com/bnomei/kirby3-boost).

## Installation

Download and copy this repository to /site/plugins/partial-cache.

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

Use a unique cache key. Keys can be organized in a folder-like structure, which makes per-page caching easy. On multilingual sites, the language code is automatically prepended.

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

You can invalidate the cache based on timestamps, for example:
- cache for a given number of minutes (same as `$cache->set()`)
- when pages with specific blueprints or IDs/UUIDs are edited
- when the site is updated or modified
- when templates or snippets change

```php
<?php

$data = partialCache('a-unique-cache-key')

    // Writes an item to the cache for a given number of minutes
    ->expires(1)

    // watch for timestamps
    ->watch([

        // If anything has been edited (cached version of $site->modified())
        'site.modified' => true,

        // If the site has been updated
        'site.update' => true,

        // Watch pages
        'pages' => [

            // Watch by IDs or UUIDs
            'id' => [
                $page->uuid()->id(),
                'some/page/id',
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
    ->expires(5)
    ->snippet('some/snippet');

echo $expires;


// Cache a snippet. Invalidates if:
// - a page with the given ID has been edited
// - a page with blueprint "home" has been edited
// - a page with blueprint "event" has been edited
// - the snippet "event-detail.php" has been edited

$event = partialCache('events/' . $event->id())
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
// - a page with blueprint "post" has been edited
// - the template "blog.json.php" has been edited

$posts = partialCache('my-api/posts')
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

Example configuration in `site.yml`:

```yaml
# site.yml

sections:
  maintenance:
    type: fields
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
