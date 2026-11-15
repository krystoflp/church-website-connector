# Church Website Connector

A reusable WordPress plugin for GeneratePress AI and WP Rocket connections,
website configuration including Imagify PDF protection, protected Used CSS operations,
PDF uploads, page/post/media metadata and optional
Redirection, The SEO Framework, Rank Math, Code Snippets and WS Form Pro management through MCP and authenticated REST.

This independent GPL-2.0-or-later plugin is not an official GeneratePress or WP
Rocket product. Vendor plugins and commercial licences are separate.

## Install and enable automatic updates

1. Download the versioned `church-website-connector-X.Y.Z.zip` from
   [Releases](https://github.com/krystoflp/church-website-connector/releases).
2. In WordPress, open **Plugins → Add Plugin → Upload Plugin**. Upload the ZIP,
   activate it, or replace the existing connector when upgrading.
3. Open **Settings → Website Connector** and follow its connection guidance.
4. New 1.7.3 installations select automatic updates once. For an existing installation,
   on **Plugins → Installed Plugins**, choose **Enable auto-updates** for Church
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

## Administrator setup and maintenance in 1.7.3

New connector installations select WordPress’s native auto-update preference once
on first administrator activation. The same default applies to the standalone
MCP Adapter only when newly installed by this connector. Existing installations,
upgrades, reactivation, moved/copied configuration and later administrator choices
are preserved. The normal Installed Plugins switch remains available; hosting
policy, plugin-update permission, scheduling and compatibility requirements apply.

Adapter setup now shows informational pending/progress messages in its dedicated
notice, readiness row and setup panel. Failure, interruption or a blocked setup
shows the action needed. Other dependency warnings and native readiness checks
remain accurate; installers still run only in authorised administrator setup.

Settings → Website Connector starts with a compact version, update-history,
auto-update and connection summary. It includes a copyable diagnostic report that
is also available in native Site Health Info. The report is allowlisted and omits
addresses, credentials, licence data, raw errors, account/activity records, content,
code and form submissions. Views require administrator permissions and make no
external diagnostic request. Clipboard failure leaves keyboard copying available.

Tools → Connector changes reads the existing four bounded audit stores and shows
at most 50 combined events, newest first. It displays time, account, helper,
operation, item ID and outcome without copying stored URLs, values or code. It is
not a complete WordPress activity log and does not expand backend retention.

Validation includes native WordPress first-install and preservation cases,
diagnostic privacy sentinels and permission checks, read-only views, every update
notice outcome, and browser-client progress/clipboard success and failure checks.

## Redirection default and automatic-update control in 1.7.4

New installations enable **Enable Redirection management through MCP** by default.
Existing settings, including disabled or missing legacy choices, stay unchanged
on upgrade and reactivation. The separate supported Redirection plugin, native
permissions, website binding, previews and explicit approval remain required.

Installed Plugins keeps the normal WordPress enable/disable auto-update control
available for the connector even before a successful GitHub check or when release
information is temporarily unavailable. The updater declares support for its own
plugin entry; it does not invent an update offer, force an update preference or
bypass website policy. The one-time installation default from 1.7.3 is unchanged.
Upgrading an earlier installation does not silently opt it into auto-updates.

Version 1.7.4 was also verified on WordPress 7.1.4 (the latest stable release at
publication) with PHP 8.3.36, including native update controls and fresh setup.
Future release checks target the latest stable WordPress version only.

Coding checks resolve the latest stable WordPress Coding Standards rules and the
latest PHP_CodeSniffer checker they officially support. The check log records
the installed package versions.

## Website configuration in 1.8.0

**Settings → Website Connector → Website configuration** includes **Prevent Imagify
from optimising PDFs**. It uses the documented `imagify_get_mime_types` filter to
remove only `pdf`, across ordinary uploads, bulk/manual optimisation and background
workers, independently of MCP sign-in. Images and other code’s exclusions stay in
place. Requires Imagify 2.3.1+; verified with 2.3.5. Missing/inactive Imagify is harmless.

New and upgrading installations enable protection when no choice is saved. Explicit
off/on choices survive later upgrades, reactivation and site rebinding. Copied site
settings stay paused until administrator review and save. Settings and diagnostics
report both the saved preference and effective state.

Exclusion preserves each PDF’s existing information. It does not assign languages,
rewrite PDFs or repair previously optimised files; restoration/replacement is
separate work. Imagify’s PDF displays and restore controls may be unavailable while
PDF support is excluded. The small configuration module retains the connector’s
existing identity and update channel. See [the plugin guide](readme.txt).

[Official filter reference](https://imagify.io/documentation/imagify-optimization-filter-hooks/).

## GeneratePress AI setup in 1.9.2

At the top of **Settings → Website Connector**, fill the GeneratePress One licence
field through your 1Password browser extension, or paste the key, then choose
**Install and activate GeneratePress AI**. Additional-field autofill may need a
one-time mapping. WordPress administrator permissions, the saved website binding
and an action nonce protect this POST-only operation. No Mac helper, local
application or setup code is used. Version 1.9.2 removes the former helper endpoint
and authorisation screen; the next administrator visit deletes old helper codes
and their expiry tasks.

Setup downloads the latest supported stable GeneratePress AI release from its
verified HTTPS update service, validates the package, and uses its native licence
manager. New installations receive the native WordPress automatic-update preference
once. Existing valid licences and later administrator choices are preserved.
Invalid/expired keys, missing entitlement, activation limits and outages produce
safe recovery messages. The inspected contract is GeneratePress AI 1.0.x; an
unreviewed release pauses setup. Releases and diagnostics contain no licence keys
or signed download addresses.

Unattended connector upgrades never install GeneratePress AI, retrieve a licence
or access 1Password. When setup is needed, an administrator warning points to the
licence form. GeneratePress AI's own updates use the licence saved on the website.

## Restore to defaults in 1.9.2

Choose **Restore to defaults** beside the status summary to enable every connector
setting and website configuration option, including Imagify PDF protection and the
single GeneratePress connection. **Allow official Codex sign-in through the
alternative WP Rocket connection** is set to disabled. The action enables tools
for existing authorised GeneratePress clients as described on the settings page.

It also enables native automatic-update preferences for **Website Connector itself**
and the installed plugins listed on the page: MCP Adapter, GeneratePress AI,
GenerateBlocks, WP Rocket, Imagify, Redirection, The SEO Framework, Rank Math,
Code Snippets and WS Form Pro. Unrelated plugin preferences remain unchanged.
Missing plugins are not installed or selected. Hosting policy and per-plugin
restrictions still apply, and a warning identifies preferences that could not be
enabled. No plugin is activated, licensed or updated by restoration.

Restoration requires a deliberate administrator POST with its own action nonce
and plugin-update permission on the reviewed, bound website. Opening settings,
reactivation and automatic upgrades never restore defaults. Later manual choices
remain until another deliberate restore.
