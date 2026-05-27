=== Kapsule Migrator ===
Contributors: kapsulecloud
Tags: migration, migrate, wordpress, backup, export
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate your WordPress site to Kapsule Cloud, or export your site for manual migration to any host.

== Description ==

Kapsule Migrator does two things:

**1. Export for manual migration (no account required)**
Package your WordPress files and database into downloadable archives and move them anywhere you like. No Kapsule account needed.

**2. Direct migration to Kapsule Cloud**
Transfer your site directly to Kapsule Cloud with one click. Connect with a migration token and the plugin handles everything.

= How the direct migration works =

1. Start a migration at kpanel.kapsulecloud.com — choose the "Plugin" path
2. Copy your one-time migration token from the Kapsule wizard
3. Install and activate this plugin on your current site
4. Paste your token — the plugin handles everything from there

The plugin scans your site, packages files in chunks (works even on large sites), exports your database, and transfers everything directly to Kapsule. Your current site is never modified.

= How the export works =

1. Install and activate the plugin (no account needed)
2. Go to Kapsule Migrate in your admin menu
3. Click the "Export Site" tab
4. Click "Export Site" — packaging runs in the background
5. Download your files and database archives when ready

= Features =

* Works with any WordPress host (no SSH or FTP credentials required)
* Chunked transfer — handles multi-GB sites without hitting PHP memory limits
* Export mode works without a Kapsule account
* Token-based auth for direct migration — no passwords stored or shared
* wp-config.php excluded from all exports for security
* Self-cleans after migration is confirmed

= Security =

* Your migration token is single-use and expires in 2 hours
* All transfers are encrypted in transit (HTTPS)
* The plugin only reads your site — it never writes to it
* wp-config.php and wp-config-sample.php are never included in exports
* Token and temporary files are deleted after migration completes

== Installation ==

= For direct migration to Kapsule =
1. Start a migration at kpanel.kapsulecloud.com/sites/migrate
2. Choose the Plugin path and copy your migration token
3. In your WordPress admin, go to Plugins → Add New → Upload Plugin
4. Upload the zip file and activate
5. Go to Kapsule Migrate in your admin menu
6. Paste your migration token and click Start Migration

= For export / manual migration =
1. Download from kpanel.kapsulecloud.com/downloads/kapsule-migrator.zip or install from WordPress.org
2. Activate the plugin
3. Go to Kapsule Migrate in your admin menu
4. Click "Export Site" tab, then "Export Site"
5. Download your archives when packaging completes

== Frequently Asked Questions ==

= Do I need a Kapsule account? =
No. The "Export Site" mode works without any account. You only need a Kapsule account for the direct migration path.

= Will this affect my live site? =
No. The plugin only reads your files and database — it never modifies anything.

= What if the migration fails? =
The plugin will show an error message with details. Contact Kapsule support and we'll sort it out. Your site is completely unaffected.

= Do I need to keep the plugin installed? =
Once migration is complete and you've confirmed your site is live on Kapsule, you can deactivate and delete the plugin.

= What files are excluded from exports? =
wp-config.php and wp-config-sample.php are always excluded. Common cache directories, node_modules, and backup directories are also skipped.

== Changelog ==

= 1.0.0 =
* Initial release — direct migration to Kapsule + standalone export mode
