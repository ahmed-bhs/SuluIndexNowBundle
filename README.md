# SuluIndexNowBundle

**Sulu bundle that integrates IndexNow API to instantly inform search engines and web crawlers about content changes.**
See https://www.indexnow.org/index for details.

## Installation

This bundle requires PHP 8.2 and Sulu 3.0.8 or newer in the 3.0 release line.

1. Open a command console, enter your project directory and run:

```console
composer require linderp/sulu-index-now-bundle
```

If you're **not** using Symfony Flex, you'll also need to add the bundle in your `config/bundles.php` file:

```php
return [
    //...
    Linderp\SuluIndexNowBundle\SuluIndexNowBundle::class => ['all' => true],
];
```

2. Register the new routes by adding the following to your `routes_admin.yaml`:

```yaml
SuluIndexNowBundle:
    resource: "@SuluIndexNowBundle/Resources/config/routes_admin.yaml"
```

3. If you don't have the IndexNow setup already, generate your key at https://www.bing.com/indexnow/getstarted. Then follow the instructions and put the key file in the `public/` folder.
4. Add the file `config/packages/sulu_index_now.yaml` with the following configuration and replace `#your key here` with your actual key:
```yaml
sulu_index_now:
    key: #your key here
    search_engines:
        IndexNow: 'https://api.indexnow.org/indexnow'
        Amazon: 'https://indexnow.amazonbot.amazon/indexnow'
        Bing: 'https://www.bing.com/indexnow'
        Naver: 'https://searchadvisor.naver.com/indexnow'
        Seznam: 'https://search.seznam.cz/indexnow'
        Yandex: 'https://yandex.com/indexnow'
        Yep: 'https://indexnow.yep.com/indexnow'
```
5. Reference the frontend code by adding the following to your `assets/admin/package.json`:

```json
"dependencies": {
    "sulu-index-now-bundle": "file:../../vendor/linderp/sulu-index-now-bundle/Resources/js"
}
```

6. Import the frontend code by adding the following to your `assets/admin/app.js`:

```javascript
import "sulu-index-now-bundle";
```

7. Build the admin UI:

```bash
cd assets/admin
npm run build
```

8. Create the table that stores the submission history:

```console
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

The bundle registers its own Doctrine mapping, so no additional configuration is
required. If you do not use migrations, `doctrine:schema:update --force` works too.

## Usage

- The admin UI is available under **Settings → Index Now**.
- Publishing a page triggers an IndexNow submission automatically, unless
  `seo.noIndex` is enabled on that page.

### Dashboard

The dashboard reports on the **current host** only, so a multi-webspace project
sees the figures of the domain it is opened from.

**Summary cards**

| Card | Meaning |
| --- | --- |
| URLs in sitemap | What the next submission will send |
| Last successful submission | Most recent run where *every* engine accepted |
| Last run | Most recent run, whatever its outcome |
| Success rate | Share of successful runs over the last 20, plus average duration |

The distinction between the two dates matters: a partially failed run does not
overwrite the date of the last real success, so a submission that silently stops
reaching some engines stays visible.

**Alert banner**

A banner appears above the cards when the setup needs attention:

- the last successful submission is more than 7 days old,
- no submission has ever succeeded,
- the last run did not reach every engine.

**History**

Every run is recorded — manual or automatic — so the history survives a reload.
Each row shows the date (absolute and relative), the trigger, the status, the
number of URLs, the duration, and one dot per search engine. Expanding a row
reveals the per-engine outcome, the batch counts, and the error message returned
by an engine that rejected the submission.

The list can be filtered by status and by trigger, and is paginated.

### Automatic submissions

Two things queue a URL:

1. publishing a page (`seo.noIndex` pages are skipped);
2. dispatching `IndexNowUrlEvent` from your own code.

```php
use Linderp\SuluIndexNowBundle\Event\IndexNowUrlEvent;

$eventDispatcher->dispatch(new IndexNowUrlEvent('https://example.com/my-page'));
```

URLs queued during a request are deduplicated and submitted once, on
`kernel.terminate`, so the visitor never waits for the search engines. The
resulting run is recorded with the trigger `automatic`; its `source` records what
queued it (`page_publish`, `event`, or both).

### HTTP API

All three routes sit behind the admin firewall, so they require an authenticated
admin user. They do not check the `sulu.module.index_now` permission
individually — that permission controls whether the navigation item and view are
shown.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/admin/api/index-now/urls` | URLs currently in the sitemap |
| `GET` | `/admin/api/index-now/status` | Cards, statistics and paginated history |
| `POST` | `/admin/api/index-now/start` | Submit every sitemap URL now |

`GET /admin/api/index-now/status` accepts `page`, `limit` (max 100), `status`
(`success`, `partial`, `error`) and `trigger` (`manual`, `automatic`):

```console
curl '/admin/api/index-now/status?status=error&trigger=automatic&page=1&limit=20'
```

```jsonc
{
  "lastRun":     { "submittedAt": "...", "trigger": "manual", "status": "success", "...": "..." },
  "lastSuccess": { "...": "..." },
  "statistics":  { "total": 20, "successful": 17, "partial": 2, "failed": 1, "averageDurationMs": 2400 },
  "history":     [ { "id": 42, "urlCount": 296, "durationMs": 2410, "engines": [], "...": "..." } ],
  "pagination":  { "page": 1, "limit": 20, "total": 57, "pages": 3 }
}
```

URLs are submitted in batches of 1000, as required by the IndexNow
specification. A run counts as successful only when every configured engine
accepted every batch.

### Translations

The bundle ships English, German and French admin translations.

Keys defined in your project's `translations/admin.<locale>.json` take
precedence over the bundle ones. If the dashboard shows outdated or partially
translated labels, remove the `app.index_now_*` keys from that file so the
bundle values are used.

## Troubleshooting

**The dashboard shows "Never" although submissions have been made.**
Only runs recorded *after* the upgrade appear; earlier ones were never stored.

**A search engine keeps failing.**
Expand its row in the history to read the error it returned. A `403` usually
means the key file is missing or does not match the configured `key` — it must
be reachable at `https://<your-host>/<key>.txt` and contain the key itself.

**Nothing happens when a page is published.**
Check that the page is not marked `seo.noIndex`, and that the submission is not
being skipped because the URL could not be generated — both cases are logged as
warnings.

**The container fails to build with a "Expected to find class" error.**
This happens when the bundle is used from a git checkout containing its own
`vendor/` directory. Update to a version that excludes it from the service
autowiring.
