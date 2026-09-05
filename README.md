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
- Publishing a page triggers IndexNow submission automatically (unless `seo.noIndex` is enabled).

### Dashboard

The dashboard shows, for the current host:

- the number of URLs currently in the sitemap,
- the date of the last **successful** submission (all search engines accepted it),
- the date and status of the last run, whichever its outcome,
- whether each run was triggered **manually** from the admin or **automatically** by a publish,
- a history of the last 20 runs, with the per-engine result of each one.

Every run is recorded, whether it was started from the admin interface or by
publishing a page, so the history survives a page reload.

### Translations

The bundle ships English, German and French admin translations.

Keys defined in your project's `translations/admin.<locale>.json` take precedence
over the bundle ones. If the dashboard shows outdated or partially translated
labels, remove the `app.index_now_*` keys from that file so the bundle values are
used.
