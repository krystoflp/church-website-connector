=== Church Website Connector ===
Requires at least: 7.0
Requires PHP: 8.3
Stable tag: 1.9.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One reusable WordPress plugin for GeneratePress AI, WP Rocket and protected
website configuration and tools through MCP. It protects PDFs from Imagify optimisation and adds PDF uploads, page/post/media metadata tools,
optional Redirection, SEO, snippet and form-design management and Used CSS clearing. Install the same ZIP on
each compatible website; no site-specific code or ZIP rebuild is needed.

== Website configuration: Imagify PDF protection ==

In Settings → Website Connector → Settings, select “Prevent Imagify from optimising PDFs” to help preserve each PDF’s own language declaration and accessibility information. Only the pdf entry is removed through Imagify’s documented imagify_get_mime_types filter. Images and other code’s exclusions are preserved. Applies to automatic uploads, bulk/manual optimisation and background workers without an MCP connection.

On for new installations and once on upgrade when the setting is missing. Explicit disabled/enabled choices are retained on later upgrades, reactivation and site rebinding. The existing site-binding rule pauses copied/moved settings until an administrator reviews and saves them. The module uses the existing settings form, permissions and nonce.

Requires Imagify 2.3.1+; verified against 2.3.5. Harmless when Imagify is absent/inactive. Settings, connection status, diagnostic report and Site Health Info show the saved and effective states, including an unsupported version or conflicting filter. No external request is made by this feature.

Prevention only: existing PDFs keep their current contents until separately restored or replaced. No language is assigned and no document is rewritten. PDF support exclusion also affects Imagify’s displays, statistics and restore controls; native recovery may need protection temporarily off as a separate controlled task. Already running remote jobs and other PDF processors are outside this setting.

The separate configuration module broadens Church Website Connector beyond MCP/REST connectivity while retaining its name, identity and update mechanism.

Official reference: https://imagify.io/documentation/imagify-optimization-filter-hooks/

== Connection options ==

With the optional GeneratePress OAuth bridge enabled, use the native GeneratePress
AI MCP server as a single primary connection for GeneratePress authoring, enabled
Church Website Connector tools and the allowlisted WP Rocket tools. Complete the
native GeneratePress sign-in and consent. A separate WP Rocket OAuth sign-in is
not needed for those tools through this connection.

The bridge supports GeneratePress AI >=1.0.0 <1.1.0. Fresh administrator activation
enables it immediately, matching the selected checkbox. Review existing authorised
GeneratePress clients; native sign-in and consent still apply. Updates and reactivation preserve
existing choices; older installations without this choice remain off. Only the fixed list of
registered, MCP-public abilities is added. Native account permissions, feature
switches and each tool's operation checks still apply.

Without the bridge, Codex needs the alternative WP Rocket sign-in option enabled
to establish a new separate WP Rocket MCP connection. GeneratePress supplies its authoring tools; the WP Rocket gateway
supplies its native cache tools and the enabled connector tools. This remains an
alternative setup. With both connection options off, Status warns in yellow that
no route is enabled for new Codex access to the additional WordPress and plugin
tools. GeneratePress’s own authoring remains independent. Existing authorised
connections are not revoked by these switches.

Settings > Website Connector supplies both native MCP addresses and the
GeneratePress authorisation-server metadata address. Its copyable setup message
reflects the saved connection method, with unavailable setup reported separately. Readiness checks do not prove
that sign-in, client discovery or an upload has completed successfully.

== Installation and updates ==

1. Open Plugins > Add Plugin > Upload Plugin in WordPress. Upload the ZIP and
   activate it as an administrator, or replace the installed version when updating.
2. Open Settings > Website Connector. Resolve any missing or inactive dependencies
   shown there, including GeneratePress AI setup and licence activation.
3. Open the connector settings to complete setup. New setups automatically
   install or activate the separate official MCP Adapter when needed. Review the
   selected features and existing authorised GeneratePress clients, then save.
   The single GeneratePress connection is already enabled for a fresh installation.
4. Copy the setup message from that page into your website project chat. Reuse an
   existing connection for this exact website where available. A new connection
   needs native website sign-in and consent. Keep passwords, tokens and licence
   keys out of chat and project files.
5. Ask the assistant to verify the exact website with
   church-connector/connection-status, then discover the tools needed for the task.
   After updating, refresh the connection or restart an already-open client if it
   retains an old tool list. Verify again after restarting.

Opening the setup page or reading status does not edit content, upload files or
clear caches. Those actions require their own authorised task. Where compatible
Used CSS services are available, saving settings can grant only the current
eligible administrator the connector's separate Used CSS clearing permission.

Activation enables the selected connector features on the saved website addresses.
New setups keep discovery compatibility on, leave alternative WP Rocket Codex
sign-in off, and select all four plugin integrations and Redirection.
The single GeneratePress connection is enabled immediately on a fresh installation. Settings can disable individual features. Reactivation on the same installation preserves
saved choices. The plugin binds to WordPress's saved HTTPS Site Address and
WordPress Address, not the request Host header or a client-supplied URL. A copied
database or changed address pauses the helpers until an administrator re-enables
them for the new installation.

This is an independent GPL plugin, not an official GeneratePress or WP Rocket
product. Its public source and releases are at:
https://github.com/krystoflp/church-website-connector
Changes in vendor interfaces may need a connector update.

== Setup defaults and MCP Adapter ==

The plugin's normal activation/deactivation controls replace the former master
"Enable the helpers on this website" checkbox. Reactivation resumes the selected
features and preserves individual choices. Existing paused configurations and
moved/copied websites stay paused until an administrator reviews and saves the
settings for that address. Deactivation does not revoke native provider keys.
The internal enabled/address binding remains a safeguard, not a checkbox.

For a new setup, the single GeneratePress method and its extended tool scope are
enabled during administrator activation. Review authorised GeneratePress clients: every authorised client gets the enabled
connector and allowlisted WP Rocket tools. Individual permissions and approval
requirements remain. Existing sites are not silently switched during upgrades.
Discovery compatibility remains on; alternative direct WP Rocket Codex sign-in
starts off. It can be enabled if a separately configured fallback is wanted.

The separate preferred dependency is the official WordPress.org MCP Adapter:
https://wordpress.org/plugins/mcp-adapter/ (slug mcp-adapter).
No adapter code or other vendor plugin is bundled in the connector ZIP.

On the Website Connector administrator setup screen, a new setup makes one
automatic nonce-protected POST to install/activate that adapter if no compatible
loaded adapter is available. It reuses a compatible adapter supplied elsewhere,
or activates an already-installed compatible preferred adapter without downloading
another copy. Active outdated adapters are reported for update, not replaced.
The automatic setup window expires after seven days; explicit setup remains
available. Ordinary page visits, REST/MCP requests and automatic connector updates
do not trigger installation. A deliberately deactivated adapter is not repeatedly
reactivated after setup completes. The administrator can explicitly retry later.

Setup requires native plugin installation and activation permissions, allowed
file modifications and the exact bound website. It uses WordPress's repository
API, upgrader and activation checks, accepts only the exact versioned official
MCP Adapter download address, and never accepts an arbitrary plugin or ZIP URL.
Automatic installation requires WordPress's direct filesystem method. Where
filesystem credentials are needed, use the native manual WordPress installation
link. No filesystem credentials are collected or stored by this connector.
Failures show a bounded explanation with retry/manual installation options;
uncertain/interrupted results require inspecting Plugins before retrying.

The adapter remains independent and has its own WordPress update controls. The
connector enables its native auto-update preference once only when newly installed
from connector 1.7.3 onward. It does not delete the adapter on uninstall or install
The SEO Framework, Rank Math, Code Snippets, WS Form Pro or commercial licences.

== Automatic updates ==

From 1.7.3, a newly installed connector selects WordPress’s normal automatic-update
preference once on first administrator activation. A separate MCP Adapter newly
installed by the connector receives the same default after its package is
validated. Existing connectors, existing adapters, upgrades, reactivation and
later administrator changes keep their saved preference. You can disable updates
on Installed Plugins at any time; the connector does not switch them back on.
Website policy and plugin-update permissions can prevent the initial default;
review the status panel and Installed Plugins if it could not be enabled.


From 1.7.1, Settings → Website Connector → Automatic updates includes Check for
updates now. This makes a fresh GitHub check, bypassing the success/failure cache,
and refreshes only this plugin’s WordPress update information. It does not install
an update. Use Update now on Installed Plugins when you want to install it.
A failed check reports an error and preserves the previous WordPress update offer.
The button requires settings and plugin-update permissions and a valid form nonce.
From 1.7.2, the result appears at the top of the settings page after the refresh.


Install version 1.5.0 or newer once, then open Plugins > Installed Plugins and
review the auto-update setting for Church Website Connector. Enable it if desired on an existing installation. WordPress handles later
updates on its normal schedule, subject to hosting policy and working scheduled
tasks. The site's administrator can disable auto-updates using the same control.
Existing connector settings, connections and feature choices are retained.

The connector checks published stable GitHub releases; drafts, prereleases,
unpublished tags and development branches are not update sources. Each release
must include its versioned plugin ZIP and matching JSON metadata with compatibility
requirements and a SHA-256 checksum. Incomplete or malformed releases are rejected,
and the downloaded ZIP is checked before WordPress installs it. Update checks are
cached for 12 hours; failed checks are cached briefly. If GitHub is unavailable,
the currently installed connector continues running.

Checks use GitHub's public HTTPS endpoints without GitHub credentials or a
WordPress sign-in. Ordinary web requests reveal the server IP and the connector's
User-Agent version; no website address, content or connection credentials are sent.
No WordPress roles, provider grants or auto-update preferences are changed by the
updater. Vendor plugin updates remain managed by their own update systems.

== Dependencies and compatibility ==

* PHP 8.3 or newer and single-site WordPress 7.0 or newer with the Abilities API.
* HTTPS Site Address and WordPress Address on the same DNS host, without custom
  ports. Different paths on that host, including subdirectory sites, are supported.
* GeneratePress AI, an active GeneratePress One licence and GenerateBlocks 2.3 or
  newer. Pro authoring needs the corresponding licensed plugins.
* A compatible MCP Adapter. GeneratePress AI 1.0.0 requires 0.6.1 or newer. A
  compatible bundled adapter can satisfy this; a duplicate plugin is not needed.
* An active licensed WP Rocket release. The connector also checks for WP Rocket's
  native MCP OAuth library. That library supports the separate WP Rocket gateway;
  its presence does not mean the primary GeneratePress connection uses WP Rocket
  sign-in. No additional third-party OAuth plugin is bundled or required.
* The optional GeneratePress bridge supports GeneratePress AI 1.0.x and its native
  OAuth/MCP classes. Unsupported versions pause that bridge.
* Used CSS clearing supports WP Rocket >=3.24 <3.25 with Remove Unused CSS enabled.
  Other versions pause that operation pending compatibility review.
* Optional Redirection tools support Redirection >=5.10.1 <5.11 and the required
  native interfaces; tested on 5.10.1. Other versions pause those tools.

Missing dependencies and identifiable outdated adapters produce administrator
warnings. Dependencies, commercial licences and vendor MCP servers are not bundled
or replaced. Local readiness, the client’s OAuth support, native sign-in and the
connected account's permissions must all be checked on the intended website.

== Optional SEO, snippet and form integrations (1.6.0) ==

New setups select all four integrations in Settings > Website Connector. Existing
websites keep their saved choices, including off choices; missing integration
flags in older configurations remain off. Each switch remains independent. Refresh the
connected client's tools after saving. Both the existing MCP connection and six
fixed authenticated REST endpoints expose the same protected operations. All
require an administrator on this connector's bound website plus native plugin
permissions. No new role, licence or OAuth grant is created.

Supported and locally tested native plugins:
* The SEO Framework >=5.2.1 <5.3; tested 5.2.1. Page/post titles, descriptions,
  canonical URLs, robots flags, social metadata and search/archive exclusions;
  selected site-wide homepage/title/social/indexing and sitemap settings.
* Rank Math >=1.0.282 <1.1; tested Free 1.0.282. Page/post SEO, focus keywords,
  robots and social fields; selected general, titles and sitemap settings. Finish
  native setup (including its normal free-account skip option if preferred).
* Code Snippets >=4.1.0 <4.2; tested Free 4.1.0. List/read, create inactive, edit,
  activate, deactivate and move site snippets to Trash. Locked/trashed/network
  snippets and permanent deletion are excluded. PHP syntax is checked during
  preview; native validation during apply can execute code. Activation and edits
  to active snippets require explicit code-execution approval and
  acknowledge_code_execution=true. Syntax validation cannot establish safety.
* WS Form PRO >=1.12.20 <1.13; tested Pro 1.12.20. List/read form designs, create
  drafts, edit labels, fields and layouts, add/update/remove fields, sections and
  tabs, and publish/unpublish. Selected per-form settings are label_render_off,
  submit_on_enter and invalid_field_focus. Existing actions/conditionals remain
  server-side; native deletion repairs references to removed design items. Submission responses, exports, resend actions,
  notification credentials, licence settings and global WS Form options are not
  exposed. Native auto-publishing, when enabled, can publish design edits;
  editing such a published form also requires native publish_form permission.

Version ranges are guards, not claims that every version in a range was tested.
Unsupported/missing native interfaces pause the integration. A selected helper
for an absent plugin displays "Not active—helper waiting"; absence is not a setup
error. Both SEO helper switches can stay selected when only one SEO plugin is
active. Selecting a helper does not install or activate its vendor plugin.
Activating a compatible vendor plugin later makes its selected helper available
to existing authorised connections, still subject to native permissions and
preview/approval requirements. Only the documented
editable subset is available; use integration-capabilities for exact schemas.
WS Form field metadata follows its native AI schema (for example required='on'
or ''), and preserves existing IDs/types in full-design updates. Use structural
operations for adding/removing items. Designs are bounded to 20 tabs, 100 sections,
100 fields and 256 KiB; lists return at most 50 items per request.

MCP ability names (prefix church-connector/):
* integration-capabilities
* list-integration-items
* get-integration-item
* preview-integration-change
* apply-integration-change
* discard-integration-change

REST paths use /wp-json/church-connector/v1/integrations/<ability-name>.
Capabilities/list/get use GET; preview/apply/discard use POST. Use standard
WordPress authentication over HTTPS: existing cookie sign-in with a REST nonce,
or an authorised account's Application Password where an external REST client
needs it. MCP clients continue using the existing native connection; they do not
need a new REST credential. Anonymous access is denied. Raw Abilities REST
execution is hidden, so it cannot bypass the preview workflow.

Every request identifies the exact saved site_url. Item requests include
integration (seo-framework, rank-math, code-snippets, ws-form), kind (post,
settings, snippet, form) and integer id. SEO settings use id=0; Rank Math settings
also require section=general, titles or sitemap. Read the current state_hash,
preview one operation with changes, review its returned diff and effects, then
apply preview_token with approved=true only after the user's approval. Creating
snippets/forms uses id=0 and operation=create. Publishing and snippet activation
need separate previews; a new form is a draft and a new snippet is inactive.

One integration preview per account expires after 15 minutes. Tokens are bound
to the account, website, installed plugin version and current item/settings state;
replacement, expiry, site moves, disabled switches or changed state prevent use.
Native permissions are rechecked before execution. Only token hashes are stored.
A per-item connector lock and single-use consumption prevent duplicate connector
writes; native editors are not locked. Saved results are read back. A failure may
follow a partial native change: inspect the item before retrying. The bounded
50-entry audit stores field names and outcomes, without code, values or tokens.
Uninstall removes connector previews/audit and preserves native plugin data.
Approval is an acknowledgement supplied by the client, not a separate human
confirmation screen in WordPress. Assistants must obtain actual user approval.

== Authentication and access ==

The primary connection uses GeneratePress AI's native OAuth flow. GeneratePress AI
1.0.x issues a non-expiring, individually revocable key for its MCP endpoint. Keep
it in macOS Keychain or the client's secure credential store. Revoke unused access
in Settings > GeneratePress AI. Enabling the bridge extends the tool list for
EVERY authorised GeneratePress AI client on that website, including existing
connections. It does not grant new WordPress roles or bypass target permissions.

The alternative WP Rocket connection uses WP Rocket's own sign-in, consent,
token and renewal handling. Its credentials are separate from GeneratePress's.
The optional Codex helper adds only the exact official client identity through
WP Rocket's native trust filter:
https://chatgpt.com/oauth/codex/client.json

This identity represents the shared Codex application, not a person, device,
project or chat. Trust permits sign-in requests, not automatic website access.
Each connection still requires an authorised WordPress account and native consent.
Other chats with access to an authorised connection can use its permissions; this
plugin does not enforce project isolation. It does not store WordPress passwords
or copy provider credentials into project files.

The discovery compatibility helper yields only the two exact root discovery
documents already handled by WP Rocket, with or without trailing slash. It leaves
GeneratePress's namespaced discovery and both native authentication providers
intact. The bridge uses GeneratePress's supported ability-list filter; it does not
replace OAuth, re-register vendor callbacks or expose arbitrary abilities.

The tested client is native Codex desktop. ChatGPT web and other clients must
support the relevant provider's OAuth flow; browser ChatGPT sign-in is not claimed
verified. Codex trust does not automatically trust a different client. Do not
weaken client verification to work around an authentication error.

Disabling the bridge removes its extra tools; it does not revoke native keys.
Deactivating or uninstalling the connector does not revoke access previously
issued by either provider. Use each provider's own connection controls to revoke
its authorisations.

== PDF uploads ==

church-connector/upload-pdf uploads an actual local PDF to the Media Library
through the selected website connection. Enable the checkbox labelled
"Enable page, post and media details and PDF uploads through this website connection".
The connected user needs the native upload_files capability. No separate REST
password, role change or additional sign-in is needed.

The settings page shows PDF upload readiness for the current administrator and
the effective website size limit. This is a local permission/readiness check,
not proof that a client has discovered the tool or completed an upload.

Supply the exact bound site_url, a PDF filename and canonical data_base64 read
from the file itself. Do not supply a local path, data URL or remote download URL.
The maximum decoded size is 10 MiB (10,485,760 bytes), or the website's upload limit
if lower. Hosting and client request limits may be lower still.

Checks cover safe filenames, canonical base64, the PDF header/end marker, fileinfo
MIME where available and the WordPress MIME allowlist. One fixed internal POST to
/wp/v2/media preserves native core REST permissions and upload policy filters.
Saved bytes are verified against the original using byte count and SHA-256.
These are file-type/integrity checks, not a full PDF parser or malware scan.

Optional title, caption and description are plain text. attach_to must identify
an existing non-attachment/non-revision post the account can edit; this can be a
page or post. The result includes attachment_id, the public file URL, saved
filename, MIME type, byte count and SHA-256. Existing image upload tools are
unchanged.

An authorised new PDF upload creates a public Media Library attachment directly;
it does not use an existing-item metadata preview token. Later edits to supported
attachment details use the metadata read/preview/apply workflow below. Repeating
an upload creates a new attachment, so inspect the Media Library before retrying
an uncertain result.
The tool does not publish pages, change account grants or clear caches. Uploaded
PDFs remain in the Media Library if the connector is uninstalled.

== Page, post and media metadata ==

The metadata tools are church-connector/rest-capabilities, get-item-details,
preview-item-update, apply-item-update and discard-item-update. All tool names
use the church-connector/ prefix. They are available through the primary
GeneratePress connection when the bridge is enabled, or the alternative native
WP Rocket gateway. These tools are enabled by default on an enabled installation,
including updates from 1.0.0. The settings checkbox for page, post and media details
and PDF uploads controls both metadata tools and PDF uploads.

Supported page/post details include title, excerpt where supported, slug, featured
image, comments/pings, categories/tags for posts and parent/order for pages.
Existing media supports plain-text title, alt text, caption and description.
The fields returned by get-item-details are authoritative for that item's core
REST controller. Use GeneratePress for block content and draft authoring.

Read the item and use its current state_hash when creating a preview. Preview
returns a normalised before/after diff without updating the item. Review it before
applying that account's preview_token with approved=true. The token expires after
15 minutes; a changed item requires a fresh read and preview. One pending metadata
preview is retained per account, separately from Redirection previews.

Apply rechecks native permissions, exact website identity, expiry and the latest
item state. Only the token hash is stored; a conditional claim consumes the exact
preview before writing. A new preview replaces the previous one. Previews can be
discarded, and WordPress cron removes expired records. Per-item locks serialise
connector writes; unrelated WordPress editors are checked optimistically.

Internal rest_do_request calls retain the signed-in WordPress user, native REST
validation, sanitisation, permission callbacks and relevant policy filters. Only
fixed core routes and declared metadata fields are used. Content, publication
status, scheduling, author, arbitrary meta, users, plugins and settings are outside
this metadata write allowlist. No arbitrary URL/route proxy is provided.

Native filters can normalise values, and an error may follow a partial change.
Inspect the returned item/error before retrying. The bounded metadata audit keeps
50 results with UTC time, user ID, item, changed field names and outcome, without
tokens or text values. Disabling REST-backed tools also closes direct callbacks.
These tools schedule no cache clears; ordinary native update hooks still apply.

== Redirection through MCP ==

The optional tools are redirection-capabilities, list-redirections, get-redirection,
list-redirection-groups, preview-redirection-change, apply-redirection-change and
discard-redirection-change, all prefixed church-connector/. Enable Redirection in
Settings > Website Connector. Version 1.7.4 enables this helper by default only
on new installations. Updates, reactivation and older saved settings preserve
the previous choice, including off. The separate Redirection plugin is still required. Use the primary GeneratePress connection
with the bridge enabled, or the alternative WP Rocket gateway. Native Redirection
permissions are still required.

Read rules and WordPress-module groups; create, edit, enable, disable or permanently
delete one plain URL-to-URL rule at a time. Editable fields include source/target,
title, HTTP code, group/order, case/trailing-slash matching and query mode: exact,
exactorder, ignore (drop) or pass (forward). Regex and conditional-rule editing,
group writes, visitor logs, plugin settings and global actions remain in the native
editor. Internal calls use only fixed native REST routes.

Ignore/pass still require any query constraints specified in the source, while
permitting extra incoming values. exactorder retains native order-sensitive rule
selection, so query-order-only redirects remain available.

Read the affected rule and use its state_hash for a preview. Creation omits
id/state_hash and supplies source, target and an existing group_id. Review the
concrete diff before applying the 15-minute, single-use, owner-bound token.
Permanent deletion has no undo and requires explicit confirmation. Apply rechecks
permissions, website, expiry, rule and group, then verifies the stored result.
Traffic counts do not invalidate previews. A website-level lock serialises
connector redirect writes; unrelated native editors are checked optimistically.

Before preview and apply, native case, trailing-slash and query matching checks
plain source overlaps. Browser dot segments, encoded paths, query order and query
forwarding are checked for self loops and local cycles among active plain rules.
Disabled rules are included in overlap checks. These checks make no external HTTP
requests and are bounded to 1,000 rules, 200 WordPress groups, 20 chain hops and
20,000 matching checks. Exceeding a limit refuses the change; use the native editor.

Regex/conditional rules, dynamic target transformations, external domain aliases,
other plugins and web-server redirects are outside this analysis. The checks do
not guarantee that every possible redirect loop is prevented. A write error may
follow a partial change; inspect the result before retrying.

The audit retains 50 outcomes with time, user, operation, ID and field names,
without tokens, URL values or visitor data. Existing redirects survive uninstall.
These tools clear neither page cache nor Used CSS. Use the website's WP Rocket
tools separately when needed. No automatic timed redirect or front-end layout
change is introduced.

== Used CSS ==

church-connector/used-css-status is read-only; church-connector/clear-used-css
changes generated CSS and associated page cache. Both use the primary GeneratePress
connection with the bridge enabled, or the alternative native WP Rocket gateway.
Reading connection-status or used-css-status does not clear anything.

An eligible administrator can receive church_connector_clear_used_css when the
helper is enabled with compatible native services. Clearing also requires both
manage_options and rocket_remove_unused_css. Other administrators are not granted
access automatically; an eligible administrator can save settings to obtain their
own grant. Execution rechecks permissions and readiness.

Prefer the smallest scope: a published, non-password-protected page/post ID.
The request must identify the exact bound website; canonical addresses must stay
on that HTTPS host and within its base path. Arbitrary URL input is rejected.
Whole-site clearing requires scope=site and confirm_site=true, plus explicit user
authorisation. Scope/approval flags are client acknowledgements, not independent
proof of human consent on the website. They do not replace native permissions.

The operation uses an atomic lock, a 60-second cooldown and a local audit of the
last 50 executions: UTC time, user ID, scope, URL and result. This connector sends
no telemetry. Failure may follow partial clearing; inspect before retrying. Used
CSS regeneration is asynchronous and must be verified separately. Clearing page
cache alone does not clear Used CSS.

The manual operation is under Tools > Church Website Used CSS. The connector does
not schedule clearing or trigger it automatically after content edits.

== Existing helpers, rollback and uninstall ==

When an older all-saints-used-css or church-toolkit-used-css helper is active,
the connector waits before advertising replacement Used CSS abilities. Keep an
administrator browser session open, deactivate the older CSS helper, then save
Website Connector settings and verify the replacement's read-only status through
the intended connection. Retain the original ZIP and plugin until verified.
A clearing test requires separate authorisation.

The old discovery/trusted-client helper can be deactivated after the replacement
and native sign-in discovery are verified. Preserve a working recovery session
and sign-in path during migration. Before changing between connection providers,
verify the replacement connection and required tools before retiring the old one.

To roll back an update, use the previous connector ZIP from an administrator
session, then verify the configured connection. If reverting to the older separate
helpers, review which bridge tools will disappear before deactivating this plugin.
Uninstall removes only connector settings, recorded Used CSS grants, previews,
locks, cooldowns, audits and expiry events. It preserves uploaded PDFs, other media,
page/post content, redirects, legacy-helper data, native licences and provider
credentials. Native authorisations must be revoked through their own providers.

== Changelog ==

= 1.9.5 =
* Show amber Waiting for Used CSS clearing when Remove Unused CSS is switched off in WP Rocket, with wording that respects the website configuration choice.
* Keep clearing unavailable until enabled and preserve important permission, website and compatibility warnings.

= 1.9.4 =
* Status reports the enabled MCP route to the additional WordPress and plugin tools. Both connection options off shows a yellow warning instead of a green separate-connections claim.
* Enabled tool helpers wait in yellow while MCP connection setup is needed; native GeneratePress authoring and Imagify PDF protection remain independent.
* Connection instructions and diagnostics reflect both-off settings without changing sign-in permissions, saved choices or existing authorised connections.


= 1.9.3 =
* Apply the single GeneratePress connection on fresh installation, matching its checked setting immediately; keep alternative WP Rocket Codex sign-in disabled and preserve existing choices.
* Use the saved primary mode consistently in status, diagnostics, checkbox state and connection instructions. Report unavailable GeneratePress setup separately instead of displaying a different connection choice.
* Replace the website-plugin checklist with a Status section and accessible green, yellow and red indicators. Consolidate all editable choices in Settings and show PDF protection results only in Status.

= 1.9.2 =
* Add an explicit Restore to defaults button: enable every connector setting and configuration option except alternative WP Rocket Codex sign-in; enable automatic updates for Website Connector itself and the installed plugins listed in settings, respecting website policy.
* Remove the private Mac helper option, setup-code screen and helper REST endpoint. GeneratePress AI setup now uses the administrator browser licence form only.
* Remove saved helper authorisations and their expiry tasks on the next administrator visit after upgrading.
* Preserve browser autofill/paste, licence error recovery, existing licence and automatic-update choices, and the unattended-upgrade warning.

= 1.9.1 =
* Put the GeneratePress AI setup warning, licence field and installation button at the top of Connector settings.
* Allow browser-based licence entry and 1Password custom-field filling without a Mac helper or setup codes.
* Use the administrator's WordPress session and an action nonce; send the licence only in POST and never repopulate it in the connector form.
* Retain the optional existing Mac helper and all unattended-upgrade, licence-error and update-preference safeguards.

= 1.9.0 =
* Keep unattended connector upgrades free of paid-plugin installation or licence access; show an administrator setup warning when needed.
* Add administrator-authorised GeneratePress AI installation and native licence activation through the private 1Password Mac helper.
* Select WordPress automatic updates once for newly installed GeneratePress AI; preserve existing installations and later administrator choices.
* Bind single-use setup codes to the approving administrator and exact website, with ten-minute expiry and no general REST access.
* Verify stable release metadata, package origin, archive paths and plugin headers; retain no connector copy of licence keys or signed package URLs.


= 1.8.0 =
* Add a separate website configuration module and administrator control to prevent Imagify PDF optimisation, preserving each document’s existing language and accessibility information.
* Remove only the pdf supported-format entry through Imagify’s documented filter (2.3.1+), across uploads, bulk, manual and background processing. Image optimisation and other exclusions are preserved.
* Enable protection on fresh installation and once on upgrade when no choice exists; preserve explicit disabled and enabled choices during later upgrades, reactivation and site rebinding. Report saved and effective state in settings and safe diagnostics.
* Exclusion prevents future optimisation; it does not repair previously optimised PDFs, assign languages or rewrite files. Plugin identity and GitHub update mechanism are unchanged.


= 1.7.4 =
* Enable Redirection management through MCP by default only on new installations; preserve existing settings and the required preview/approval workflow.
* Keep the normal Installed Plugins auto-update enable/disable control available before a successful GitHub check, without forcing updates or changing saved preferences.


= 1.7.3 =
* Show informational MCP Adapter setup progress, synchronised across notices and readiness rows; warn only when setup fails or needs administrator action.
* Enable native automatic updates once for a new connector installation and an MCP Adapter newly installed by it; preserve existing and later administrator choices.
* Add a compact status panel with update-check history and automatic-update policy.
* Add a credential-free diagnostic report, accessible copy control and native Site Health information.
* Add Tools → Connector changes to read the existing bounded audit records without exposing URLs, code, content or form responses.

= 1.7.2 =
* Show update-check success, warning and error messages immediately below the Website Connector page heading so the result is visible after the page refreshes.

= 1.7.1 =
* Add an administrator-only Check for updates now button in Settings → Website Connector. It bypasses the GitHub cache and refreshes the connector’s WordPress update information without installing an update.
* Report available, current, incompatible and failed checks; preserve settings, automatic-update choices and other plugins’ update information.

= 1.7.0 =
* Preselect the single GeneratePress method for new setups, confirmed on the first settings save; preserve existing connection choices.
* Enable all four plugin helpers by default for new setups, with dormant waiting status for unavailable plugins and unchanged approval checks.
* Remove the master checkbox; retain normal activation/deactivation, saved feature choices and copied-site/address safeguards.
* Install or activate the separate official MCP Adapter during new administrator setup using WordPress core APIs; include explicit retry and manual fallback.
* Keep discovery compatibility enabled and alternative direct WP Rocket Codex sign-in off in new setups.
* Add PHP 8.3, native installer, setup-client and WordPress coding-standard checks.

= 1.6.0 =
* Add optional authenticated REST and MCP access to The SEO Framework and Rank Math page/post and site-wide SEO settings.
* Add Code Snippets Free management with previews and explicit code-execution acknowledgement.
* Add WS Form Pro design and selected form settings with native permissions and preview/approval.
* Keep all four integration switches off until explicitly enabled; exclude submission records and vendor credentials.

= 1.5.0 =
* Add WordPress-native updates from published stable GitHub releases.
* Verify release identity, compatibility metadata and downloaded ZIP integrity.
* Preserve native administrator auto-update choices and existing connection settings.

= 1.4.1 =
* Refresh Website Connector settings wording for the primary GeneratePress
  connection and the separate WP Rocket alternative, including the setup message.
* Distinguish the installed WP Rocket connection library from completed sign-in.
* Show PDF upload readiness and the current size limit. Explain public files,
  optional metadata, returned links and the difference from metadata previews.
* Preserve saved settings, authentication, permissions and tool operations.
* Align this readme with the current checkbox labels and connection-reuse message;
  clarify PDF readiness and the difference from existing-item metadata previews.

= 1.4.0 =
* Add church-connector/upload-pdf through existing MCP authentication and native
  WordPress upload permissions, including the GeneratePress bridge allowlist.
* Validate PDF filenames, canonical base64, file type and size up to 10 MiB;
  preserve WordPress upload policy checks and verify saved bytes with SHA-256.
* Support plain-text PDF metadata and attachment to an editable post/page;
  return the attachment ID and public URL without publishing pages or clearing
  caches. Existing image upload tools are unchanged.
* Add PDF readiness to connection status and verify uploads, size boundaries,
  invalid-input rejection, native permissions and existing connector behaviour.
* Refresh this readme throughout: document the single GeneratePress connection,
  retain separate WP Rocket sign-in as an alternative and place PDF guidance
  before the changelog. This documentation correction changes no PHP code.

= 1.3.1 =
* Repair WP Rocket 3.24 settings tool enum formatting through the native ability
  registration filter on the enabled, bound-site GeneratePress bridge. Preserve
  native allowed values, callbacks and permissions.

= 1.3.0 =
* Add an optional fixed allowlist of existing connector and WP Rocket tools to
  the native GeneratePress AI OAuth endpoint. Defaults off; native permissions
  remain unchanged.
* Explain non-expiring GeneratePress keys and the effect on every authorised
  client at that endpoint. Review existing connections before enabling.

= 1.2.1 =
* Reject equivalent-source conflicts and encoded/query/dot-segment self loops.
* Check bounded local plain-rule chains, including forwarded query values, at
  preview and again at apply, before consuming the single-use preview.
* Ignore native last-selected-group display state when checking stale previews.
* Restrict Redirection compatibility to >=5.10.1 <5.11 with interface checks.
* Display optional Redirection readiness and warn when enabled but unavailable.
* Clarify shared Codex client identity, account authorisation and approval flags.

= 1.2.0 =
* Add optional Redirection management through the existing MCP connection.
* Add dedicated read, preview, apply and discard tools with native REST
  permissions, single-use owner-bound approvals, stale-state checks and read-back.
* Add fixed single-rule operations and a separate administrator feature toggle.

= 1.1.0 =
* Add REST-backed page/post and media metadata tools through existing MCP sign-in.
* Add permission-checked reads, concrete previews, owner-bound single-use approval
  tokens, stale-state checks and a bounded audit.
* Support portable installation with generic tool names and saved site identity.

= 1.0.0 =
* One site-independent installation ZIP with native connection setup information.
* Dependency warnings, exact Codex trust and focused discovery compatibility.
* Protected Used CSS abilities, status, manual tool and safe legacy-helper checks.
* PHP 8.3 minimum, administrator-only setup and exact activation-time site binding.

== Administrator status and diagnostics (1.7.3) ==

The top status panel shows the installed and latest known stable connector
versions, native auto-update preferences and policy, last release check, last
successful check, primary connection and actionable compatibility issues. Opening
settings makes local reads only; use Check for updates now for a fresh GitHub
lookup. Update history stores timestamps, outcome and a validated version only.

Pending MCP Adapter installation is informational. During the administrator setup
request, notices and readiness rows show installation or activation progress.
Confirmed success reloads the page to load the adapter’s native interfaces;
failed, blocked or interrupted setup explains the action needed. Native readiness
remains false until compatible interfaces are actually available. Other dependency
warnings remain visible.

The Diagnostic report on Settings → Website Connector has a labelled read-only
field and Copy diagnostic report button, with keyboard-copy fallback. The same
report is available in Tools → Site Health → Info → Church Website Connector.
It contains only selected versions, readiness and helper flags, automatic-update
policy, and release-check timestamps/outcome. Website addresses, passwords, keys,
licence data, native error transcripts, account details, activity rows, code,
website content and submitted responses are excluded. It is not sent anywhere
automatically. Only administrators with manage_options may access it.

Tools → Connector changes shows up to 50 existing recorded connector changes,
newest first, with local time, WordPress account, helper, operation, item ID and
result. It combines the existing metadata, Redirection, integration and Used CSS
audit stores. Their existing limits of 50 entries per store remain unchanged;
no second activity database is created. This is not a full WordPress activity
log, and it excludes URLs, field values, code and form responses. Diagnostics do
not include these activity records. Reading these screens does not edit content,
clear caches, grant access or change update preferences.
