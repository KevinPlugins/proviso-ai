=== Kevin Ability Guard for MCP ===
Contributors: kevinplugins
Tags: mcp, ai, abilities, model context protocol, approval
Requires at least: 6.9
Tested up to: 7.0.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Set every AI ability to always allow, require approval, or always reject — and see what each one actually did.

== Description ==

MCP plugins let an AI agent act on your site. Whichever one you use, the result is the same: a model connected in Claude, Cursor or Codex can create posts, edit content, change settings and delete things, and by default nothing stands between the model deciding and WordPress doing.

Ability Guard puts a decision point in front of every ability. For each one you choose:

* **Always allow** — it runs, and what it did is recorded
* **Require approval** — it is held. Nothing changes until a human says yes
* **Always reject** — it never runs, and the agent is told to stop trying

You do not need the ability's author to have built any of this. The guard attaches as abilities are registered, so it covers plugins that have never heard of it.

= It works with whichever MCP plugin you use =

There are many MCP server plugins now, each with its own endpoint, its own authentication and its own way of exposing tools. This plugin is not tied to any of them.

Abilities are guarded at the moment WordPress registers them, so a rule applies no matter which server later exposes that ability. And because MCP is a standard protocol, tool calls are recognised by their shape rather than by any plugin's URL — so a call is inspected the same way whether it arrives through one MCP plugin, another, or one released next year.

That matters more than it sounds. Several MCP plugins register abilities *and* run their own server that calls the underlying code directly. Guard only the ability, and a rule you set looks armed while the agent's actual route sails past it. This plugin watches both, so the rule holds whichever way the call comes in.

= How it decides what an ability does =

Most tools guess from an ability's name, or trust whatever its author declared. Both are wrong often enough to matter — an ability called `get_report` can still send email. This plugin prefers evidence, strongest first:

1. **Watching it run.** While an ability executes, the plugin records what it touches: posts, pages, options, users, terms, comments, and writes to any plugin's own tables. Seen reading and never writing, it earns read-only status.
2. **Reading its code.** Before an ability has ever run, its callback is inspected for database writes, outbound requests and file changes. This catches a write on the first call rather than the second.
3. **Its declared annotation.** Believed, but only provisionally. If an ability claims read-only and then writes, that claim is revoked on the spot and it is held from then on.
4. **Its name.** The weakest signal, used last, and never enough on its own to auto-allow.

Every row in the admin list shows which of these produced its classification, so you always know whether you are reading a verified result or an assumption. When nothing can be determined the plugin says **unknown** rather than guessing, and unknown is held rather than allowed.

= Some things are never auto-allowed =

Sending mail, making outbound HTTP requests and writing files leave no trace in your database, cannot be observed after the fact, and cannot be undone by anything. A clean run history is not evidence that they are safe. Abilities that do these are held for approval no matter how many times they have run without incident.

= What a held request looks like =

Nothing has changed yet. The call is queued, and the agent receives a clear message: approval is pending, do not retry, and do not attempt the same change another way. It can check the outcome later through an ability provided for that purpose, so it waits rather than looking for a way around.

You review it in wp-admin, with the arguments the agent proposed. Values that look sensitive are redacted. Approve and it runs; reject and it never does.

= Undo =

Changes to posts, pages, options, users, terms and comments can be reverted from the audit log. If someone has edited the target since, the revert is refused rather than overwriting their work. Writes to a plugin's own tables are recorded but cannot be reverted — and the plugin says so plainly instead of pretending otherwise.

= What this plugin does =

* Sets every registered ability to always allow, require approval, or always reject
* Covers abilities from any plugin, without their authors doing anything
* Classifies read versus write by observation and code inspection, not naming
* Holds mail, outbound requests and file writes for approval, always
* Queues held calls for human review, with the proposed arguments shown and sensitive values redacted
* Records every execution, with an undo for core objects
* Supports different rules per caller, so one agent can be trusted where another is not
* Requires approval from a capability, named users, specific roles, or any of several approvers — and can require all of them rather than the first
* Expires unanswered requests after a period you choose
* Learning mode: watch and classify without holding anything, so you can see the list before you set rules
* Reports coverage: which MCP tool calls it saw, and which did not map to a registered ability

= What this plugin does not do =

* It does not run an MCP server, transport or authentication. Those are your MCP plugin's job.
* It does not register abilities of its own, other than one small ability that lets an agent look up whether its pending request was approved.
* It does not replace an ability's own permission checks. Those still run. This is a layer on top, not a substitute.
* It does not govern MCP tools that were never registered as abilities. Some plugins serve tools directly without registering them; those are outside its reach, and the Coverage screen names them rather than leaving you to assume you are covered.
* It does not phone home, collect telemetry, or make any external network request.

= Requirements =

* WordPress 6.9 or later — the Abilities API arrived in core in 6.9
* PHP 7.4 or later
* At least one plugin that registers abilities, and usually an MCP server plugin to expose them

= Source code =

Development happens in the open at [github.com/KevinPlugins/mcb-ability-guard](https://github.com/KevinPlugins/mcb-ability-guard).

The admin screen is a Vue application. What ships in `assets/` is the compiled bundle; the readable source it was built from lives in the `app/` directory of that repository, along with the build configuration, so anyone can rebuild it and compare.

= Privacy =

Nothing leaves your site. There is no external service, no telemetry and no account. Pending requests, audit entries and behaviour profiles are stored in your own database.

Arguments passed to abilities are stored with each queued request so a reviewer can see what was proposed. Values that look sensitive are redacted before display. The audit log can be purged on a schedule you set, because those arguments may contain personal data.

= About MCP =

Model Context Protocol is an open specification originally developed by Anthropic. This plugin is a third-party project and is not affiliated with, endorsed by or sponsored by Anthropic, nor by any of the MCP server plugins it works alongside.

== Installation ==

1. Install Ability Guard for MCP from the plugin directory, or upload the plugin zip.
2. Activate it.
3. Go to **Tools &gt; Ability Guard**.

On a site that already has abilities registered, leave **Learning mode** on to begin with. The plugin watches and classifies without holding anything, so you can see what your abilities actually do before deciding anything. When the list looks right, turn learning mode off and set rules on the abilities you care about.

A reasonable starting point is: always allow the reads, require approval for anything that writes, and always reject anything you never want an agent touching.

== Frequently Asked Questions ==

= Does it work with my MCP plugin? =

It should. The guard attaches to abilities as WordPress registers them, so it does not care which plugin later exposes them. Tool calls are recognised by the MCP protocol's own message shape rather than by any particular plugin's endpoint, so no per-plugin support is needed.

= Do I need to write any code? =

No. Everything is set from the admin screen.

= What is the difference between "require approval" and "always reject"? =

Require approval holds the call and waits for a person. Always reject refuses it outright, tells the agent not to retry and not to attempt the same change another way, and records the attempt. Use reject for things no agent should ever do, so you are not answering the same prompt repeatedly.

= What happens to the AI agent while a request is held? =

It is told the request is queued, that nothing has changed, and that it should not retry. It can look up the outcome later through an ability the plugin registers for exactly that purpose. Agents told only "denied" tend to look for another route, which is why the wording matters.

= Will it slow my site down? =

It does nothing on front-end page loads. The work happens only while an ability is executing, which is an admin or API request.

= What if an ability has never run? =

Its code is inspected before it runs, which is usually enough to tell a read from a write. If that is inconclusive it is marked unknown and follows your default rule. Nothing is ever labelled read-only on a guess.

= Can it undo what an agent did? =

Core objects — posts, pages, options, users, terms, comments — yes, provided nothing else has edited them since. Writes to a plugin's own tables are recorded but not reversible. Email, outbound requests and file writes cannot be undone by anything, which is why abilities that do those are never auto-allowed.

= Can different agents have different rules? =

Yes. A caller proven by authentication can be given its own rules. A caller that merely says who it is can be told apart in the audit log and can have rules made stricter, but never looser — otherwise any client could claim to be your trusted one.

= Can I require more than one person to approve? =

Yes. Approval can be granted by a capability, by named users, by roles, or by a mix — and you can require all of them rather than just the first to respond.

= An MCP tool is not in my list. Why? =

It was probably never registered as an ability. Check the Coverage screen: tool calls that did not map to a registered ability are listed there. Its author would need to register those tools with the Abilities API for them to become governable.

= Does it replace the permission checks abilities already have? =

No. Those still run. This is an extra layer, not a substitute — an ability that refuses a user without the right capability still refuses them.

= Does it send data anywhere? =

No. No external service, no telemetry, no account.

== Screenshots ==

1. The abilities list: every registered ability with its rule — always allow, require approval, always reject — and how its read/write classification was reached.
2. A held request awaiting review, showing the arguments the agent proposed with sensitive values redacted.
3. The audit log: what ran, what it changed, and one-click undo for core objects.
4. Coverage: which MCP tool calls were seen, and which did not map to a registered ability.
5. Settings: learning mode, default rule, who may approve, and how long requests wait before expiring.

== Changelog ==

= 1.0.0 =
* Initial release.
* Per-ability rules: always allow, require approval, always reject.
* Covers abilities registered by any plugin, with no cooperation needed from their authors.
* Recognises MCP tool calls by protocol shape, so a rule holds whichever server exposes the ability.
* Read/write classification by observation and by inspecting the ability's code, with the basis shown for each.
* Mail, outbound requests and file writes are never auto-allowed.
* Approval queue with per-caller rules, multi-approver support and optional expiry.
* Audit log with undo for posts, pages, options, users, terms and comments.
* Learning mode for classifying without holding anything.
* Coverage reporting for MCP tool calls that map to no registered ability.

== Upgrade Notice ==

= 1.0.0 =
First release.
