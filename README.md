# AI Router for Moodle

Routes each AI request in Moodle to a provider chosen by rules the site writes, rather
than to whichever provider happens to come first in the site order. Rules can look at
the course, the category, the person's role, the action and the length of the prompt;
a request can be charged to a key the person or the course brought; and what was spent
is recorded, reported and capped.

| Component | Path | What it is |
| --- | --- | --- |
| `local_airouter` | [`local/airouter`](local/airouter) | The plugin. Rules, budgets, monitoring, keys people bring, and the settings pages. **Start here:** [its README](local/airouter/README.md) documents the whole thing. |

Earlier builds also shipped a connector, `aiprovider_router`, at `ai/provider/router`.
It is no longer used. The plugin README says how to move a site off it: upgrade
`local_airouter` first, then uninstall the connector.

## Licence

GPL v3 or later. See [LICENSE](LICENSE).
