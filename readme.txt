=== Maintenance ===
Contributors: biscuitstudios
Tags: maintenance, coming soon, maintenance mode, holding page
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hide a site behind a holding page you build yourself. Maintenance (503) or Coming Soon (200), with a secret preview link.

== Description ==

See the README on GitHub for the full description, requirements, and known
limitations: https://github.com/biscuitstudios/bs-maintenance

Built and maintained by Biscuit Studios for our own client sites. Published
as-is, with no support. Forks welcome.

== Installation ==

1. Download the zip from the Releases page on GitHub.
2. Plugins > Add New > Upload Plugin.
3. Activate.

== Changelog ==

= 0.5.0 =
* New: the plugin now has its own icon on the Plugins and Updates screens.
  Nothing supplied one before. A wordpress.org plugin gets its artwork from the
  .org API, and this one is served from GitHub Releases, so the update response
  had to carry the icon itself or the screens fall back to a generic plug.
* Note: the icon will not appear on this update. The installed version is what
  answers the update check, and the version being replaced has no icon to give.
  It shows from the next update onward.

= 0.4.0 =
* New: the plugin now offers its own updates on the Plugins screen. Until now
  the Update URI header pointed at GitHub and nothing answered, so no site was
  ever told a new version existed and every release had to be uploaded by hand.
  Updates are read from the repo's published releases.
* Note: this only starts working once a build containing it is installed. A site
  on an older version has no code to ask with, so the first install of this
  release is still a manual upload.

= 0.3.0 =
See https://github.com/biscuitstudios/bs-maintenance/releases
