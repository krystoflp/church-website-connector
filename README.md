# Church Website Connector

A reusable WordPress plugin for GeneratePress AI and WP Rocket connections,
protected Used CSS operations, PDF uploads, page/post/media metadata and optional
Redirection management through MCP.

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

## Development and releases

This repository contains the distributable connector and portable release tools.
The wider development toolkit, site profiles and private verification records
are maintained separately. Do not add website configuration, credentials,
licence keys, vendor fixtures or client data here.

Run `php tests/updates.php` for the isolated updater boundary checks, lint the PHP
files, and run `python3 scripts/build.py` to produce a deterministic ZIP, release
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
