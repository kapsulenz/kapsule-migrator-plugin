===  Kapsule Migrator ===
Contributors: kapsulecloud
Tags: migration, migrate, wordpress, kapsule
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate your WordPress site to Kapsule Cloud — free, zero downtime, no credentials shared.

== Description ==

Kapsule Migrator is the easiest way to move your WordPress site to Kapsule Cloud.

= How it works =

1. Start a migration at kpanel.kapsulecloud.com — choose the "Plugin" migration path
2. Copy your one-time migration token from the Kapsule wizard
3. Install and activate this plugin on your current site
4. Paste your token — the plugin handles everything from there

The plugin scans your site, packages files in chunks (works even on large sites), exports your database, and transfers everything directly to Kapsule. Your current site is never modified.

= Features =

* Works with any WordPress host (no SSH or FTP credentials required)
* Chunked transfer — handles multi-GB sites without hitting PHP memory limits
* Real-time progress in your Kapsule dashboard
* Token-based auth — no passwords stored or shared
* Self-cleans after migration is confirmed

= Security =

* Your migration token is single-use and expires in 2 hours
* All transfers are encrypted in transit (HTTPS)
* The plugin only reads your site — it never writes to it
* Token and temporary files are deleted after migration completes

== Installation ==

1. Download from kpanel.kapsulecloud.com/migrate (Plugin path → Download Plugin)
2. In your WordPress admin, go to Plugins → Add New → Upload Plugin
3. Upload the zip file and activate
4. Go to Kapsule Migrate in your admin menu
5. Paste your migration token and click Start Migration

== Frequently Asked Questions ==

= Will this affect my live site? =
No. The plugin only reads your files and database — it never modifies anything.

= What if the migration fails? =
The plugin will show an error message with details. Contact Kapsule support and we'll sort it out. Your site is completely unaffected.

= Do I need to keep the plugin installed? =
Once migration is complete and you've confirmed your site is live on Kapsule, you can deactivate and delete the plugin.

== Changelog ==

= 1.0.0 =
* Initial release
