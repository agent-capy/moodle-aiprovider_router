# Changelog

All notable changes to this plugin are recorded here. Dates are the date of the
release, and versions follow [semantic versioning](https://semver.org/).

## 0.1.0 — 2026-09-20

The first release. Everything below is implemented, covered by tests, and built
against Moodle 5.0; continuous integration runs the suite on Moodle 5.0, 5.1 and
5.2 with PHP 8.3 and 8.4.

⚠ It has not yet been run on a site other than the author's, which is why this
is a beta. Please try it on a test site, and say what does not work.

### Routing

- Delegates a request to another configured AI provider instead of answering it,
  choosing the target per request rather than once per site.
- Rules are evaluated in priority order, first match wins. Conditions within one
  rule are combined with AND; values within one condition with OR.
- Conditions: placement, course, course category, user role, action type, prompt
  length, and budget.
- Fallback chains: a target that fails, times out, or answers with nothing is
  passed over for the next one. Loops are prevented and a delegate's exception
  is contained rather than allowed past the AI subsystem.
- A dry-run screen answers "which target would this request go to, and why".

### What it records

- Every request: the target it went to, the model that answered, the tokens it
  used, how long it took, and an estimated cost.
- Costs are worked out when the request is recorded and stored, so a later change
  to a rate does not rewrite history. Currencies are never converted.
- Daily summaries, kept after the detail rows are removed.
- Dashboards for the site, per course, per user, and a smaller per-course view
  for teachers.

### Bringing your own key

- Per-user and per-course API keys, stored encrypted with `\core\encryption`.
- A policy controlling who may bring one, and rules that send a request with
  somebody's own key rather than the site's.
- Whoever brought a key can cap what it spends, over a calendar month or a
  rolling period.

### Budgets

- Route by how much has been spent already — by the site, a course or a person,
  over a rolling period or a calendar month.
- Measured in money **or in requests**. Requests are always countable, so a
  budget works on a site that has entered no rates at all, which is the normal
  case for a model you run yourself or a free tier written in requests.
- A daily task tells the people who watch a budget when one is crossed, once per
  crossing rather than once per day.

### Checks and safety

- Nine entries in Moodle's checks report, covering configuration that looks
  right but cannot work: an empty delegation target, a rule that can never
  match, rates that are missing, a provider instance that cannot carry an action
  it offers, and others.
- Errors shown to a user never name a provider, a rule or an instance.
- Logging a request never causes an AI request to fail.

### Known limits

- Only two of the four intended providers have been exercised against a live
  API so far.
- `maturity` is beta: the plugin is feature complete for what it sets out to do,
  and is looking for sites to try it.
