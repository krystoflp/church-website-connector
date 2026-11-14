# Church Website Connector

A reusable WordPress plugin for GeneratePress AI and WP Rocket connections,
protected Used CSS operations, PDF uploads, page/post/media metadata and optional
Redirection, The SEO Framework, Rank Math, Code Snippets and WS Form Pro management through MCP and authenticated REST.

This independent GPL-2.0-or-later plugin is not an official GeneratePress or WP
Rocket product. Vendor plugins and commercial licences are separate.

## Install and enable automatic updates

1. Download the versioned `church-website-connector-X.Y.Z.zip` from
   [Releases](https://github.com/krystoflp/church-website-connector/releases).
2. In WordPress, open **Plugins → Add Plugin → Upload Plugin**. Upload the ZIP,
   activate it, or replace the existing connector when upgrading.
3. Open **Settings → Website Connector** and follow its connection guidance.
4. On **Plugins → Installed Plugins**, choose **Enable auto-updates** for Church
   Website Connector. WordPress's normal schedule and hosting policy apply.

Requires PHP 8.3+, single-site WordPress 7.0+, compatible GeneratePress AI/MCP and
WP Rocket dependencies. Read [the plugin guide](readme.txt) for compatibility,
feature permissions, authentication and recovery information.

The updater reads published stable GitHub releases from this repository. A release
must include its versioned installation ZIP and matching JSON metadata with
compatibility requirements and SHA-256. Downloaded ZIPs are verified before
installation. Drafts, prereleases, standalone tags and development branches are
not update sources. Existing settings and administrator auto-update preferences
are preserved. A GitHub outage leaves the installed connector running.

Use the attached installation ZIP. GitHub's automatic source archives also
contain development files and are not the prepared installation package.

## Setup defaults in 1.7.0

New setups preselect the single GeneratePress connection. Review existing
GeneratePress clients and save to enable it. All four plugin helpers start
selected and wait when their plugin is absent or unsupported. Discovery
compatibility stays on; direct WP Rocket Codex sign-in starts off. Existing
websites retain their saved choices during updates and reactivation.

Normal plugin activation/deactivation replaces the master checkbox. Copied or
moved websites still require administrator review and saving at the new address.
During new administrator setup, the connector installs or activates the separate
[official MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) if needed.
Compatible existing adapters are reused. Failures provide retry/manual options;
completed setup does not automatically undo later adapter deactivation. Nothing
is installed from ordinary site visits or automatic connector upgrades.

## Optional integrations

Choose the integrations in **Settings → Website Connector**, then
refresh your connection. The SEO Framework and Rank Math cover individual SEO
fields and selected site-wide settings, including sitemaps. Code Snippets Free
supports reviewed management; new snippets stay inactive, and activation or
active-code edits require explicit code-execution approval. WS Form Pro covers
form design and selected per-form settings, with separate draft/publish steps.
Submission records, notification credentials and licence settings are excluded.

All changes use a current-state read, a concrete preview and a short-lived,
account-bound approval token. Native plugin permissions and version guards apply.
See [the plugin guide](readme.txt) for supported versions, exact scope, six tool
names and authenticated REST paths. New setups select all four integrations; automatic updates preserve existing
feature choices, including disabled integrations.

## Development and releases

This repository contains the distributable connector and portable release tools.
The wider development toolkit, site profiles and private verification records
are maintained separately. Do not add website configuration, credentials,
licence keys, vendor fixtures or client data here.

Run `php tests/updates.php`, `php tests/integrations.php`,
`php tests/adapter-setup.php` and `node tests/setup-client.js`, lint the PHP
files on PHP 8.3, and run `python3 scripts/build.py` to produce a deterministic ZIP, release
metadata and checksum in `dist/`. The complete connector regression and isolated
WordPress upgrade checks are run in the maintained development toolkit before a
release is published.

The **Check and release** workflow runs portable checks on code changes. To publish
a tested version, open **Actions → Check and release → Run workflow** and select
**Publish the tested version as a stable release**. It reruns checks, builds the
package, creates a draft, attaches both required files, and publishes only after
both uploads complete. Each published revision must have a new version number.
Existing published versions are never overwritten by this workflow.

Published source and packages are public. Only authorised repository maintainers
can publish releases. Sites need no GitHub account or access token to update.

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Immediate update checks (1.7.1)

In **Settings → Website Connector → Automatic updates**, select **Check for updates
now**. The nonce-protected administrator action bypasses both successful and failed
GitHub caches and refreshes only the connector’s native WordPress update entry.
It reports whether a stable update is available, the installed version is current
or newer, requirements are incompatible, or the check failed. It does not install
anything; use **Update now** on **Installed Plugins** to install an available update.
Other plugin update entries, their check schedule, connector settings and automatic
update preferences are preserved. Failed checks keep the previous WordPress update
information. The button requires both settings and plugin-update permissions and
appears once the website has installed 1.7.1 or newer.

From **1.7.2**, update-check success, warning and error messages appear directly
below the Website Connector page heading, where they are visible after the page
refreshes. The check button remains in the Automatic updates section.
