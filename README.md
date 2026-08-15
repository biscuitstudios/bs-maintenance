# Maintenance

Hides a WordPress site behind a holding page while you work on it.

Built and maintained by [Biscuit Studios](https://biscuitstudios.com/) for our
own client sites. Published because it may be useful to others, not because it
is a supported product. See [Support](#support).

## What it does

- **Two response modes.** Maintenance returns `503` with `Retry-After`, which
  tells crawlers the outage is temporary and keeps your existing pages in the
  index. Coming Soon returns a plain `200`, for a site that has nothing to come
  back to yet. Both are `noindex`.
- **Two ways to supply the page.** Pick any published WordPress page, built
  normally in your theme or page builder, and the plugin renders it in place
  with the visitor's URL left untouched. Or write standalone HTML and CSS, which
  loads nothing from the theme at all and so still works while the theme is
  half-built.
- **Secret access link.** Share a URL that lets a client see the real site
  without logging in. No account needed.
- **Logged-in bypass,** with an optional redirect for signed-in users.
- **A page for WordPress's own update screen.** The `wp-content/maintenance.php`
  drop-in that core shows during an update, generated from your own text instead
  of the default grey message.

## What it deliberately does not gate

Only HTML page views are hidden. Left reachable: the login screen and admin,
REST, cron, XML-RPC, feeds, sitemaps, `robots.txt`, and WooCommerce's
`wc-api`, `wc-ajax` and `wc-auth` endpoints.

That last one matters if you run a store. `wc-api` is how payment gateways
deliver callbacks, and answering one with a `503` can make a gateway treat a
completed charge as failed. Scheduled work, recurring payments, and their
notification emails all continue to run with maintenance on.

## Page caches, and the one thing to check first

**This is the part that bites, and it is worth two minutes before you rely on
the secret link.**

The gate works per browser, using a cookie. A host page cache works per URL, and
nothing connects the two. So on a caching host, the real page rendered for the
one person holding the secret link can be stored and served to everybody, while
the admin screen still says the site is hidden.

The plugin defends against this by sending cookies whose names page caches
already skip. That works on most nginx-based hosts, but it depends on a list the
host chose, not one the plugin controls.

So there is a **Test this host** button on the settings screen. It makes real
HTTP requests to your own site and tells you which of three situations you are
in: no page cache found, safe, or not safe. Run it before you share a secret
link on a host you have not used before.

## Requirements

- WordPress 6.3 or later
- PHP 8.2 or later
- Write access to `wp-content/` if you want the update-screen drop-in

## Installation

Download the zip from [Releases](https://github.com/biscuitstudios/bs-maintenance/releases),
then **Plugins → Add New → Upload Plugin**. Settings live under **Maintenance**
in the admin menu.

## Uninstalling

Deactivating removes the `wp-content/maintenance.php` drop-in. Deleting the
plugin removes its settings. Neither touches a `maintenance.php` written by
anything other than this plugin; those are detected and left alone.

## Support

None, in the usual sense. This is published as-is, and we make changes when our
own client work calls for them.

You are welcome to fork it. If you find a genuine security problem, please
report it privately using the **Security** tab on this repository rather than
opening it in public.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
