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

## Provider order

Moodle tries AI providers in the order configured for the site and returns the first
answer it gets, so **the router only does anything if it comes first**. Creating a
provider instance puts it at the end of that order, which means a freshly installed
router is never reached and appears to do nothing at all.

The plugin reports this on *Site administration → Reports → System status*:

| Check | Reports |
| --- | --- |
| Number of AI Router instances | More than one router instance exists. Only the lowest numbered one is used; delete the rest from the AI provider list. |
| AI Router in the provider order | The router is absent from the order, so it is tried only after every other provider has refused the request. |
| AI Router position in the provider order | The router is not tried first. An error in *Router only* mode; in *Alongside other providers* mode this may be deliberate, so it is reported for information only. |
| Providers ahead of the AI Router | A provider that comes earlier handles the same actions and will answer first. |
| Leftover entries in the provider order | The order still names instances that have been deleted. Moving providers up and down works on positions in that list, so leftovers can make reordering appear to do nothing. |

Each check links to **AI provider order** (`/ai/provider/router/order.php`), which is the
only page that changes the order. It shows the current order entry by entry, and what the
order would become, before anything is written. The change is made with your session key
over POST, requires `moodle/site:config`, and is recorded in the configuration log.

Two details are deliberate there:

- The **empty first entry** in the order is kept. Moodle's enable and disable handling
  tests the result of searching the list for truthiness, so a provider sitting at the very
  first position is duplicated when enabled and left behind when disabled. Keeping that
  entry empty keeps every real provider clear of it.
- Moving the router to the front **does not reorder anything else**. The other providers
  keep their order relative to each other.

## Behaviour when a target fails

The router tries its candidates in order and returns the first usable answer.

| What the target does | What the router does |
| --- | --- |
| Answers | Passes the answer through unchanged, including the model, finish reason and token counts |
| Reports an error | Tries the next candidate. If none is left, reports the last status code, so a 429 stays a 429 |
| Throws | Tries the next candidate. Moodle does not catch exceptions on the way to a provider, and the providers that ship with Moodle catch Guzzle's `RequestException` but not `ConnectException`, so an unreachable endpoint would otherwise end the whole request |
| Answers with nothing | Tries the next candidate, because an empty answer shown as though it had worked is worse than a failure |
| Answers with nothing after running out of tokens | Reports it rather than retrying. Another target would spend its budget the same way, and shortening the input is something the user can act on |

Messages shown to users never name a provider, a rule or an instance. Details that would
identify a target — including what an exception said — go to the developer log instead.

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
