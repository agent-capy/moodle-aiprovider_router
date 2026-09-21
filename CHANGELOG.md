# Changelog

All notable changes to this plugin are recorded here. Dates are the date of the
release, and versions follow [semantic versioning](https://semver.org/).

## Unreleased

Fixes from a second independent review of the same code.

### Fixed

- A rule that routes by budget no longer ends the request unless the budget has
  actually run out. A rule asking to be used *once* a limit has been passed fails
  every time the limit has not been passed, and a course budget asked about a
  request belonging to no course fails because there is no budget there at all.
  Both were being read as "the money has gone", which on a site running the
  router alongside other providers stopped every request it made.
- A provider the site has told not to accept keys people bring now refuses them
  at the moment of use, not only at the moment of registration. Keys registered
  before the setting was changed were still being used, and a fallback chain
  could still land on such a provider.
- Recording the result of a key test no longer writes back the rest of the key.
  Testing a key puts a request to somebody else's server, and a key rotated or a
  spending limit lowered while that was in flight was being undone when the
  answer came back.
- The key a course pays with is deleted when the course is. What the course spent
  stays; the secret does not. An upgrade step removes the keys of courses already
  deleted.
- Redacting a delegate's exception now covers the field a brought key was
  actually put in, rather than only fields whose name looks like a key's.
- A rate limit set on the router itself is no longer a way round everything
  else. Moodle checks a provider's rate limit before calling the provider, so
  the router was never asked about the requests that followed, and the ones a
  budget would have refused went to the next provider on the site's key.
- A request being charged to a key somebody brought is no longer finished on
  the site's money when the target answers with nothing. The same failure on
  the site's own key still falls through to the next provider, as before.
- Somebody the site no longer allows to bring a key can still remove the key it
  is holding for them. The screen offered nothing but a notice, while the link
  to it was deliberately kept for exactly this case.
- The rule tester reports where the request would really go. It decided from
  the list of targets the rule form offers, which deliberately includes targets
  that are switched off or cannot carry the action, so it could name one
  provider while a real request went to another. It now asks the router.
- Privacy: a request recorded against somebody's own user context is found when
  that context is searched for users; the record that a spending limit was
  announced is declared, found, exported and removed; and a deletion request
  clears who registered a course key only in the course it approved.
- Costs are shown in the currency they were recorded in. A site that changes
  its currency keeps both -- costs are worked out when a request happens and
  nothing is ever converted -- and the old rows were being relabelled with the
  new currency, which is a different number rather than the same one again.
  Where a period holds two currencies the headline total says so instead of
  adding them up.
- A day's figures no longer vanish from the chart when the site changes
  timezone. A summary is stamped with the midnight in force when the task ran,
  and the chart was looking the stamps up exactly, so the totals read as an
  empty week while the breakdown beside them still counted everything. Two old
  days landing in one new day are added together rather than one replacing the
  other.
- Keeping summaries for less time than a budget reaches back is refused. That
  setting did not make the figure unknown, which is what this plugin does
  everywhere else it cannot measure something; it made it smaller, so a limit
  that had been reached was under the limit again.
- A role condition matches the roles Moodle gives people without assigning
  them. Every logged in account holds the authenticated user role and nobody
  holds an assignment for it, so a condition naming it could be chosen on the
  screen and satisfied by nobody.
- Budget notices know which stretch of time they are about. A limit reached in
  January stayed on record into February, so February's crossing was read as
  already announced -- and a subject that spent nothing in the new month was
  never looked at, so nothing cleared the old note. The same subject held to
  the same amount over a calendar month and over a rolling period is now two
  limits rather than one silencing the other. **The notices already recorded
  are cleared by the upgrade**, so a limit already reached is announced once
  more on the first run after it.
- A budget set by a rule that has expired is no longer announced, and a budget
  set by a rule about one course is no longer announced for other courses: that
  rule could never have restricted them. The per-course usage screen follows the
  same rule.
- What a target used before answering with nothing is no longer lost. A target
  can succeed, report the tokens it charged for, and return nothing usable; the
  request moves on to the next target and the money does not come back. It is
  added to the row the request gets rather than written as a row of its own,
  because one row is one request everywhere in these reports. Each attempt is
  priced against the provider that ran it, and a total with an unpriceable part
  in it stays unknown rather than becoming a smaller number.

### Fixed again

A third review found that several of the fixes above were narrower than the
problems they were for. These finish them.

- A fallback chain now writes one row per attempt, against the target and the
  key that paid for it, instead of adding what an earlier attempt spent to the
  row of whichever target answered. Charging the first one's spending to the
  second key blocked a key that had spent almost nothing and let through the
  one that had spent its limit. One request is still one request: the extra
  rows say they are not to be counted as one, and every count of requests
  reads that rather than counting rows.
- A model name too long for the column is recorded as no name rather than
  taking the whole row down with it. The insert failed, the failure was
  swallowed so that recording can never break a request, and the result was a
  request that happened and left no trace at all.
- Limits people put on their own keys are stamped with their period, as rule
  budgets already were, so a limit reached in January is announced again in
  February.
- A budget set by a rule about a category is about the courses in that
  category, including those further down. Only a rule that named courses one
  by one was being read, so a category rule's budget was announced to every
  course on the site.
- Every figure on a usage screen is in the currency the period was recorded
  in, and where the period holds two, no money figure is given -- in the
  tables, on the chart and in the exported file, not only in the headline.
- Removing history a budget still reaches back into is refused by the settings
  form, which now counts limits on brought keys as well as rule budgets, and
  is prevented at the purge itself, which is the only place that sees a budget
  written after the retention was shortened.
- After a change of timezone, a day that begins before the point the
  summariser reached is counted from that point rather than skipped whole.
- The rule tester no longer says a request would be sent when the key it needs
  is registered and cannot be read. It says what would really happen.

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

### Making a refusal mean something

- **Make refusals final**, on by default. Moodle tries each provider in turn and
  reads every failure as "that one could not help", so a request turned down
  because a budget had been reached, or because a brought key could not be used,
  would otherwise be answered by the next provider on the site's own key. Where
  the setting is on, those refusals stop the request instead.
- A budget that has been reached is reported as its own reason rather than as
  "no rule matched", so that a request no rule was ever about still passes to
  the next provider, which is what running the router alongside other providers
  is for.
- The refusal is recorded before it is raised, so the usage reports and the
  budgets that read them are unaffected. Moodle's own `ai_action_register` gets
  no row, which is the cost of the only mechanism the AI subsystem offers.

### Checks and safety

- Eleven entries in Moodle's checks report, covering configuration that looks
  right but cannot work: an empty delegation target, a rule that can never
  match, rates that are missing, a provider instance that cannot carry an action
  it offers, which providers would answer a request the router turned down, an
  eligibility policy people can admit themselves to, and others.
- Errors shown to a user never name a provider, a rule or an instance.
- Logging a request never causes an AI request to fail.

### Known limits

- Only two of the four intended providers have been exercised against a live
  API so far.
- Making a refusal final stops Moodle's loop; it cannot take the other providers
  out of Moodle's list. The *What happens when the AI Router says no* check
  reports which of them sit behind the router.
- `maturity` is beta: the plugin is feature complete for what it sets out to do,
  and is looking for sites to try it.
