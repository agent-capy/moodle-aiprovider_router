# AI Router for Moodle

Routes each AI request in Moodle to a provider chosen by rules the site writes, rather
than to whichever provider happens to come first in the site order. Rules can look at
the course, the category, the person's role, the action and the length of the prompt;
a request can be charged to a key the person or the course brought; and what was spent
is recorded, reported and capped.

This repository holds **two components**, and both are needed:

| Component | Path | What it is |
| --- | --- | --- |
| `local_airouter` | [`local/airouter`](local/airouter) | The plugin. Rules, budgets, monitoring, keys people bring, and the settings pages. **Start here:** [its README](local/airouter/README.md) documents the whole thing. |
| `aiprovider_router` | [`ai/provider/router`](ai/provider/router) | The connector. A handful of classes with no logic of their own. |

## Why two

Moodle decides whether an AI action is enabled, and which actions a provider offers, by
testing whether the plugin's name begins with `aiprovider_`. A provider class placed
anywhere else is quietly treated as though it were a placement. So the half Moodle's AI
subsystem talks to has to be an `aiprovider` plugin.

Everything else is better off outside it, because Moodle never reads an `aiprovider`
plugin's `settings.php`: a router that kept its rules and budgets there could not have
a settings page in the administration tree at all.

Install both. `aiprovider_router` declares a dependency on `local_airouter`, so Moodle
will say so if only one is present.

## Licence

GPL v3 or later. See [LICENSE](LICENSE).
