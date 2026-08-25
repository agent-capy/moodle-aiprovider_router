# AI Router for Moodle (`aiprovider_router`)

An AI **provider** plugin for the Moodle AI subsystem that acts as a *router*: instead of
talking to a model itself, it decides — per request — which configured AI provider should
handle the call, and delegates to it.

> **Status: early development (alpha).** The plugin installs and registers with the AI
> subsystem, but routing is not implemented yet. Development starts 2026-09.

Developed as part of a 2026 domestic research and development project funded by the
[Moodle Association of Japan](https://moodlejapan.org/) (MAJ).

## Planned features

- **Dynamic routing** — choose a delegation target based on placement, course/category,
  user role, action type, and prompt length
- **Fallback chains** — if a target fails, times out, or returns an invalid response,
  fall through to the next one
- **BYOK (bring your own key)** — per-user and per-course API keys, stored encrypted with
  `\core\encryption`, with a condition framework controlling who may register a key
- **Usage monitoring** — log delegation target, model, tokens and estimated cost, with
  dashboards for site administrators and teachers

## Requirements

- Moodle **5.0** or later
- PHP 8.3 or later (as required by your Moodle release)

Development targets Moodle 5.0 — the lowest supported release — so that APIs introduced
in 5.1/5.2 are not used by accident. CI runs against both 5.0 and 5.2.

## Installation

Copy this directory to `ai/provider/router/` inside your Moodle installation, then visit
*Site administration → Notifications* (or run `php admin/cli/upgrade.php`) to complete
the install.

> Moodle caches the list of present plugins, so if you copy the files with `rsync` or
> similar, run `php admin/cli/purge_caches.php` **before** the upgrade — otherwise Moodle
> will not detect the new plugin.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
