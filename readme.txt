=== KapsuleHost Migrator ===
Contributors: kapsulehost
Tags: migration, migrate, wordpress, backup, export
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.5.3
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate your WordPress site to KapsuleHost, or export your site for manual migration to any host.

== Description ==

Kapsule Migrator does two things:

**1. Export for manual migration (no account required)**
Package your WordPress files and database into downloadable archives and move them anywhere you like. No Kapsule account needed.

**2. Direct migration to KapsuleHost**
Transfer your site directly to KapsuleHost with one click. Connect with a migration token and the plugin handles everything.

= How the direct migration works =

1. Start a migration at kpanel.kapsulehost.com — choose the "Plugin" path
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
1. Start a migration at kpanel.kapsulehost.com/sites/migrate
2. Choose the Plugin path and copy your migration token
3. In your WordPress admin, go to Plugins → Add New → Upload Plugin
4. Upload the zip file and activate
5. Go to Kapsule Migrate in your admin menu
6. Paste your migration token and click Start Migration

= For export / manual migration =
1. Download from kpanel.kapsulehost.com/downloads/kapsule-migrator.zip or install from WordPress.org
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

== Screenshots ==

1. The "Migrate to Kapsule" tab — paste your migration token and start a direct transfer to KapsuleHost.
2. The "Export Site" tab — package your site for download without a Kapsule account.
3. Migration running — chunked transfer progress in real time.
4. Export complete — download your files and database archives when packaging finishes.

== Changelog ==

= 1.5.3 =
* Fixed: this screen said "piece 3 of 10" while your KapsuleHost panel said "piece 2 of 10" at the same
  moment, and this screen's own PIECES SENT box said 2 as well. One count, three renderings, two of
  them a step ahead. The count of pieces that have arrived is now rendered the same way everywhere.

= 1.5.2 =
* Fixed: this screen and your KapsuleHost panel showed different amounts moved, 473.6 MB here against 495 MB there. They were two different counts, not two ways of writing one: this screen showed what your browser believed it had sent, and the panel showed what had actually arrived. Both now show what has arrived, which is the one that matters.
* Fixed: the time remaining differed between the two screens, and the panel's wording could read "about 1 minutes left". There is one estimate now, worked out in one place, and both screens show it.
* Fixed: if KapsuleHost did not answer the moment this page loaded, you were told we could not be reached. A single lost answer is now retried once before that, so a brief hiccup no longer looks like an outage. When we genuinely cannot be reached the screen still says so plainly, and still refuses to guess.

= 1.5.1 =
* Fixed, and it is the reason for this release: this plugin has never been able to update itself. WordPress asks a plugin with its own update address for a version, and requires that answer to contain a field called "version". This plugin sent the same number under a different name, so WordPress discarded the answer without an error and every site stayed on whatever version it was first installed with, no matter how many releases came out. That is why fixes reported as done kept coming back: they were correct, they were published, and they could not reach you. Update to this release once by hand and every release after it arrives on its own.

= 1.5.0 =
* Fixed: the percentage on this screen and the percentage on your KapsuleHost panel were different numbers. Both were honest and they were answering different questions: this screen showed how far through the upload you were, and the panel showed how far through the whole move, which carries on after the upload with unpacking, importing and rewriting the addresses inside your site. There is now one number and both screens show it.
* Fixed: the piece count differed between the two screens for the same reason. This screen counted what it had sent and KapsuleHost counted what had arrived, which are never the same while a piece is in flight. Both now show what has actually arrived.
* Fixed: stopping a move from your KapsuleHost panel did not reach this screen, so it carried on showing a live transfer for a move that had ended. It now finds out on the very next piece and shows you that it was stopped, and which screen stopped it.
* Fixed: a long value in the fourth box, such as your new web address, ran outside its box instead of wrapping.
* The white underlines under the buttons on this screen are gone. They were fixed in the previous release and could not reach you: that release went out under a version number that had already been used, so WordPress never offered it to sites that already had that version, and browsers kept serving the stylesheet they had already cached. This release has a new version number, which is what makes the fix arrive.

= 1.4.1 =
* Fixed, and it is the whole reason for this release: starting a migration could make your existing site slow to a crawl or stop loading altogether, including the Kapsule screen you started it from. The plugin kept the list of every file it was going to move in a place WordPress reads back into memory on every single visit to your site. On a site with 127,977 files that list was 34 MB, and reading it needed 150 MB of memory, more than most hosting plans allow. The list now sits in a file next to the copy being prepared, and each piece reads only its own part of it.
* That list was also never removed once a migration finished, so a site that had already moved kept paying for it on every visit indefinitely. Updating to this version removes it.
* If your browser tab was closed or the page stopped responding part way through, it always did pick up where it left off rather than starting again, and it still does.

= 1.4.0 =
* Fixed, and it is the whole reason for this release: the copy of your database this plugin made replaced every percent sign in your content with a 66 character internal marker, and never put it back. A percent sign is in every percent-encoded link and image name, in stylesheets, in prices, and inside stored settings, where the change also breaks the length count WordPress uses to read them back, so the setting is discarded entirely. On one real site there were 120,410 of them. Percent signs now survive the copy exactly as they are.
* That marker was also what made some pages fail to move at all. It made page addresses longer than the column that holds them, so the database refused the row or shortened it silently, and pages that arrived with a shortened address would have returned "not found" on the moved site.
* The export now checks its own work before sending anything, and refuses to hand over a copy that still carries the marker rather than uploading a damaged one.
* The copy now starts with the same settings a standard database export uses, including the time zone. Without it, every date and time in your site could shift by the difference between your old server's clock setting and the new one, with nothing to say it had happened.

= 1.3.1 =
* Fixed: when this site could not continue a move (a piece the server no longer knows about, or an
  administrator permission that has gone), the plugin retried five times and then said "We could not
  reach KapsuleHost after 5 tries", quoting KapsuleHost's own clear answer inside its own apology. It
  had reached KapsuleHost perfectly. A permanent answer is now shown as what it is, straight away.

= 1.3.0 =
* Fixed, and it is the reason for this release: this screen used to say "Move complete. Your site is
  on KapsuleHost" the moment the upload finished, with "Database: Copied" printed beside it. The
  upload finishing is not the move finishing. Everything that can go wrong (unpacking your files,
  putting them in place, importing your database, rewriting your addresses, checking the result
  serves) happens afterwards, on our side. One customer read that screen while their database import
  was about to fail.
* The plugin now reports the state of the JOB, read from KapsuleHost, and cannot say a move finished
  until KapsuleHost says it finished. If the move fails, this screen says so, says which step it
  stopped on, and shows what our server actually reported.
* "Database: Copied" is now a fact rather than a word: it says Imported or Not imported, from what
  the import actually did.
* If we cannot reach KapsuleHost to check on your move, the screen says that, instead of showing you
  the last thing it happened to know.
* Your site is still only ever read, never changed, at every one of these steps.

= 1.2.0 =
* The plugin now speaks all 16 languages KapsuleHost sells in, including right-to-left Arabic. Every
  screen, every button and every error message, not just the front page.
* Fixed: choosing "start over" could make the next migration skip files it believed were already
  sent, and report success having transferred nothing.
* Fixed: a dropped connection is now retried in your browser, where you can see it happening, with
  the piece being retried and the time until the next attempt shown. Running out of attempts pauses
  the move rather than failing it, because everything already copied is kept.
* Fixed: resuming a migration no longer rebuilds pieces the server already has.
* Fixed: prepared pieces are now deleted as soon as they are delivered, so migrating no longer needs
  double your site's size in free space.
* Fixed: a migration run from the scheduler uploaded the database under a name the server did not
  recognise, and reported it as a failed upload.
* Rebuilt the interface around what is actually moving: pieces sent, data transferred, files counted.

= 1.0.7 =
* Improved upload reliability: server now tracks which chunks have been received, so interrupted transfers resume correctly without re-uploading complete chunks
* Upload manifest now includes chunk count to improve progress accuracy

= 1.0.6 =
* PharData fallback for PHP hosts without the zip extension (previously only ZipArchive and system tar were tried)
* Added cancel button so in-progress migrations can be cleanly aborted

= 1.0.5 =
* Fixed skip-pattern matching for top-level directories (was incorrectly including some cache dirs)
* Added automated test suite for the packager component

= 1.0.4 =
* WP.org compliance: added capability check on all AJAX endpoints, improved SQL escaping, added text domain, added screenshots

= 1.0.3 =
* Improved accessibility: focus states on interactive elements; mobile-responsive admin CSS

= 1.0.2 =
* Added standalone export mode (no Kapsule account required)
* Dual-distribution: separate CDN and WordPress.org builds to ensure correct update channel

= 1.0.1 =
* Security: wp-config.php and wp-config-sample.php excluded from all exports and migration packages

= 1.0.0 =
* Initial release — direct migration to KapsuleHost
