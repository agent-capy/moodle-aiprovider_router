# AI Router for Moodle (`aiprovider_router`)

An AI **provider** plugin for the Moodle AI subsystem that acts as a *router*: instead of
talking to a model itself, it decides — per request — which configured AI provider should
handle the call, and delegates to it.

> **Status: early development (alpha).** Delegation works end to end: pick a target in
> the router's settings and every AI request the router receives is run against that
> target, with a fallback chain, loop prevention and error mapping. There is no rule
> engine yet, so the target is the same for every request regardless of context.
> Do not use this on a live site.

Developed as part of a 2026 domestic research and development project funded by the
[Moodle Association of Japan](https://moodlejapan.org/) (MAJ).

## Planned features

- **Dynamic routing** — choose a delegation target based on placement, course/category,
  user role, action type, and prompt length *(delegation engine done; rules pending)*
- **Fallback chains** — if a target fails, times out, or returns an invalid response,
  fall through to the next one
- **BYOK (bring your own key)** — per-user and per-course API keys, stored encrypted with
  `\core\encryption`, with a condition framework controlling who may register a key
- **Usage monitoring** — log delegation target, model, tokens and estimated cost, with
  dashboards for site administrators and teachers

## Settings

A router instance has two settings, on the provider instance form.

| Setting | Description |
| --- | --- |
| Operating mode | *Router only* expects every AI request to come through the router, which needs to be first in the provider order. *Alongside other providers* leaves requests the router declines to whichever provider comes next. |
| Default delegation target | The provider instance that handles a request when no rule picks one. Without it the router reports itself as not configured, so core skips it rather than handing it requests it cannot serve. |

Only one router instance can exist on a site. The form refuses a second one, and if a
second is created another way it stands down rather than competing with the first.

## Requirements

- Moodle **5.0 to 5.2**
- PHP 8.3 or later (as required by your Moodle release)

Development targets Moodle 5.0 — the lowest supported release — so that APIs introduced
in 5.1 and 5.2 are not used by accident. CI runs every push against 5.0 and 5.2 on both
PHP 8.3 and 8.4.

Moodle 5.3 is due in October 2026. It will be added to CI from November 2026 and the
supported range extended once it has been verified.

## Installation

Copy this directory to `ai/provider/router/` inside your Moodle installation, then visit
*Site administration → Notifications* (or run `php admin/cli/upgrade.php`) to complete
the install.

> Moodle caches the list of present plugins, so if you copy the files with `rsync` or
> similar, run `php admin/cli/purge_caches.php` **before** the upgrade — otherwise Moodle
> will not detect the new plugin.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
