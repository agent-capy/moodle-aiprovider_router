# AI Router for Moodle (`local_airouter`)

A *router* for the Moodle AI subsystem: instead of talking to a model itself, it
decides — per request — which configured AI provider should handle the call, and
delegates to it.

> **Upgrading from an earlier build.** Earlier builds came as two plugins: this one
> and a connector, `aiprovider_router`, at `ai/provider/router`. The connector is no
> longer used. Upgrading this plugin carries the router provider instance's settings
> over to the Routing policy page — the default delegation target, what happens when no
> rule matches, and the actions the instance was enabled for, which become actions
> placed under the router — and then deletes the instance.
>
> Once the upgrade has run, uninstall **AI Router** (`aiprovider_router`) under
> *Site administration → Plugins → Plugins overview*, or with
> `php admin/cli/uninstall_plugins.php --plugins=aiprovider_router --run`, and delete
> its directory. ⚠ **Upgrade first.** Uninstalling the connector deletes the instance,
> and with it the settings the upgrade would have carried over.

> **Status: beta, 0.1.0.** Everything described below is implemented and covered by
> tests, and CI runs the suite against Moodle 5.0, 5.1 and 5.2 on PHP 8.3 and 8.4. It
> has not yet been run on a site that is not the author's, so please try it on a test
> site rather than a live one. Reports of what does not work, or does not read clearly,
> are very welcome — finding that out is what this release is for.
> See [CHANGELOG.md](CHANGELOG.md) for what is in it.

Developed as part of a 2026 domestic research and development project funded by the
[Moodle Association of Japan](https://moodlejapan.org/) (MAJ).

**日本語の導入・設定マニュアルがあります → [doc/manual-ja.md](doc/manual-ja.md)**
(a manual in Japanese, written as a walk-through rather than as a translation of this
reference).

## Features

- **Dynamic routing** — choose a delegation target by placement, course, category, user
  role, action type and prompt length
- **Fallback chains** — if a target fails, times out, or returns an invalid response, fall
  through to the next one
- **Usage history** — every request is recorded with the target it went to, the model
  that answered, the tokens it used and an estimated cost
- **Dashboards** — what the site used, day by day and by target, action and model, for
  administrators, and a smaller per-course view for teachers
- **Daily summaries** that outlive the detail rows, which are kept for a set period
- **BYOK (bring your own key)** — per-user and per-course API keys, stored encrypted with
  `\core\encryption`, a policy controlling who may bring one, and rules that send a
  request with somebody's own key instead of the site's
- **Budget conditions** — route by how much has been used already, counted in money or
  in requests, by the site, by a course or by a person, over a rolling period or a
  calendar month
- **Key owner limits** — whoever brought a key can cap what it spends, for a calendar
  month or a rolling period

- **Budget notices** — a daily task tells the people the matching report would admit, and the
  course or person a budget is about, when one has been reached

## Settings

The router's screens are at **Site administration > AI > AI Router**.

| Page | What it is for |
| --- | --- |
| Routing policy | Whether the router routes at all, what happens when no rule matches, and the default delegation target |
| Actions the AI Router must answer | Which actions Moodle brings to the router. Only these reach it |
| Routing rules | The rules, in the order they are considered |
| AI Router rates | What each model costs, which is what budgets are measured against |
| Keys people bring | Whether a provider may be used with somebody's own key |
| Usage | What was asked for, by whom, and what it cost |

**Route AI requests through the AI Router** is one switch above everything else. Off,
the site behaves as it would without the plugin, and nothing is forgotten: the actions
placed under the router, the rules and the keys are all still there, and switching it
back on puts them in charge again. It is a way to find out what the plugin is doing
for a site without uninstalling it.

The Routing policy page holds two more settings.

| Setting | Description |
| --- | --- |
| When no rule matches | *Send it to the default delegation target*, or *decline the request*. A declined request is not offered to another provider: it fails, and Moodle records it as a failed request. Declining is how a site keeps AI spending to the cases its rules describe. |
| Default delegation target | The provider instance that handles a request when no rule picks one, and the one a request falls back to if the target a rule chose fails. Not needed on a site that routes entirely by rule and declines the rest. With neither this nor any rule, the router has nowhere to send anything, and requests for the actions placed under it are refused. |

## Actions the site places under the router

Moodle tries AI providers in the order configured for the site and takes the first
answer. The order says which provider Moodle *prefers*; it cannot say which provider
answers, and it knows nothing about rules, budgets or whose key should pay. For a site
using the router to decide those things, that is the difference between a policy and a
hope.

So the router is not a provider in that order. An action is placed **under** it on
**Actions the AI Router must answer** (`/local/airouter/managed.php`), and Moodle then
brings every request for that action to the router, and offers it to nobody else
afterwards:

- a refusal -- no rule claimed the request, the budget has run out, the key somebody
  brought was refused -- comes back as an ordinary failed request. The placement shows
  what it shows for any failure, and **Moodle records it in its own AI action log**.
- a target that merely breaks is still retried, among the targets the rules allow, and
  nowhere else.
- if the router has neither a default delegation target nor any rules, the request is
  refused rather than passed on. That is the point of choosing it here, and it has a
  cost worth stating plainly: **an action placed under the router before the router is
  set up stops working until it is**.

An action not placed there does not reach the router at all. Moodle handles it in the
provider order exactly as it would without this plugin, and no rule, budget or brought
key applies to it.

Two consequences to decide about before turning this on:

- **Refused requests are stored.** Moodle records a refusal as it records any other
  failed request, prompt included. The prompt is not shown in the standard AI usage
  report, which lists token counts rather than text, and it is covered by the Privacy
  API for export and deletion. ⚠ Moodle has no retention setting for these records, so
  they are kept indefinitely.
- ⚠ **Only one plugin can do this at a time.** The router takes its place by defining
  the AI manager in Moodle's dependency injection container. If another plugin defines
  the same entry, whichever is registered last wins and nothing warns about it - the
  site would go on displaying its rules and budgets with none of them consulted. The
  *Actions placed under the AI Router* status check exists for that: it reports an error
  when the manager in use is not this plugin's, and names the class that took it.
- ⚠⚠ **Code that builds its own manager is not covered.** Everything in Moodle asks
  the container for the manager, which is what makes this work at all, but a plugin
  can write `new \core_ai\manager(...)` instead and never ask. Such a request does not
  reach the router: no rule, budget or brought key is consulted, and nothing can detect
  it from here. If you install something that does this, that part of the site is
  outside the arrangement. Nothing shipped with Moodle does it.

## Status checks

The plugin reports on *Site administration → Reports → System status*. On a site where
the router has neither a default delegation target nor any rules, the checks other than
the first say that there is nothing to check yet.

| Check | Reports |
| --- | --- |
| Actions placed under the AI Router | An action the site placed under the router cannot reach it, either because the router has neither a default delegation target nor any rules or because another plugin has taken over Moodle's AI manager. Reported only where at least one action has been placed there. |
| Rule delegation targets | A rule names a provider instance that no longer exists. Requests matching it fall through to the next rule. |
| Keys brought by users and courses | A brought key cannot be decrypted, which happens when a site is restored without the key file under the site data directory. |
| How people qualify to bring a key | The eligibility policy rests on a profile field the person it describes can fill in, so they can admit themselves. Reported for information where every condition is required, and as a warning where any one will do. |
| Rates for budget conditions | A budget has too few rates to be measured against, so the rules carrying it match later than they should, or never. |
| Budget history | A budget looks back further than the usage this site still holds. |
| Record gaps | The record of requests and attempts has holes in it, so a provider's bill may not match the reports. |

The router's pages are in the administration tree, under *Site administration > AI >
AI Router*, so they have the settings navigation and the breadcrumb Moodle gives any
administration page.

## Rules

Rules are managed at **Routing rules** (`/local/airouter/rules.php`), under
**Site administration > AI > AI Router**, and linked from the site status report.

The list shows the rules in the order they are considered, with what each one requires,
where it delegates, whose key pays for it, and buttons to reorder, copy, switch off or
delete. Two things are
called out there, because both are easy to create and hard to spot afterwards:

- a rule with **no conditions**, which takes every request that reaches it;
- any rule **below** such a rule, which nothing can reach;
- a rule asking for a **brought key** at a provider nobody has said the key field of,
  which can never be honoured and whose only symptom is that it never claims anything.

A rule naming a provider instance that has since been deleted is flagged in the list and
reported by the **Rule delegation targets** status check. Requests matching it fall
through to the next rule rather than failing, so nothing breaks — but the rule is not
doing what it says.

### Testing a rule set

**Test the rules** (`/local/airouter/ruletest.php`) asks for a course, a user, an
action, a placement and a prompt, and shows what each rule did with that request: matched,
skipped, which conditions were not satisfied, or not reached because something above it
matched first. Nothing is sent to any provider and nothing is recorded; the rules are
evaluated by exactly the code a real request uses.

The placement is the one thing the form has to supply rather than observe. A real
request is identified by what called it, and nothing calls this page except you, so a
rule limited to a placement would never match here unless the choice on the form were
carried all the way through to deciding where the request goes. It is: what the screen
says a rule did and what it says the request would do are the same judgement, made once.

It reports the prompt's length in characters, which is what prompt length conditions
compare against, and an estimated token count beneath it. Tokens are the unit cost and
context windows are thought about in, so the estimate is there to help you settle on a
character threshold — but no rule depends on it.

The estimate uses one character per token for CJK text and four for everything else. This
version has no screen for changing those ratios; they are read from the plugin
configuration settings `tokenratiocjk` and `tokenratioother`, which for now means the
command line:

```
php admin/cli/cfg.php --component=local_airouter --name=tokenratiocjk --set=1.2
```

Getting them wrong costs a misleading figure on that screen and nothing else. A screen for
them is to come with the usage monitoring, where they can be adjusted next to the token
counts providers actually charged.

### Import and export

Not available in this version. Rules name their target by provider instance id, and those
ids do not mean the same thing on another site, so a file moved between sites would
produce rules pointing at the wrong providers. Copy an existing rule instead when you want
a variation on it.

## How a target is chosen

Rules are considered in priority order, and the first rule that matches decides where the
request goes. A rule matches when **every** condition on it is satisfied, and a condition
listing several values is satisfied by **any** of them — so "teacher or manager" is one
condition, while "teacher, in this course" is two.

| Situation | What the router does |
| --- | --- |
| A rule matches and its target can be used | Delegates there, with the default delegation target behind it as a fallback |
| A rule matches, asks for a brought key, and there is one | Delegates there with that key, followed only by the other instances the same payer has a key for |
| A rule matches, asks for a brought key, and there is none | Moves on to the next rule. Holding a key is part of what such a rule requires |
| A rule matches but its target has been deleted, switched off, or cannot perform the action | Moves on to the next rule. There is nothing to carry the rule out with, and stopping there would strand the request |
| No rule matches | Follows the *When no rule matches* setting |
| *When no rule matches* is set to decline | The default delegation target is not used as a fallback either. An administrator who keeps unclaimed requests away from a provider does not expect a failure to send one there |

### What a refusal is worth

A request that reaches the router is never offered to another provider afterwards,
whatever the router decided. What the router's reasons still decide is whether it
tries another of its own targets first:

| The router says | What happens |
| --- | --- |
| A rule fitted this request and its budget has been reached | **Stops.** A spending limit that another target could answer past is not a limit |
| A key somebody brought cannot be read, or was refused, or every instance they hold a key for has been tried | **Stops.** Carrying on would move the cost onto the site, which is the opposite of what was asked |
| Nothing is configured to handle this request | **Stops.** A half configured site should be visibly half configured |
| No rule claimed this request, and *When no rule matches* is set to decline | **Stops.** This is the site saying the request is not to be answered |
| A target was unreachable, broke, or returned nothing usable | **Tries the next target** the rules allow -- among the instances the same payer holds a key for, where the request was being charged to a brought key |

Either way, the person making the request sees the placement's ordinary failure, and
the request is in the router's usage records and in Moodle's own AI log.

Conditions that depend on something the request does not carry — a course, when the
request came from outside any course; a placement, when it cannot be identified — are not
met, so the request falls out of narrow rules rather than into them.

Prompt length is measured in **characters**, not tokens. A token count could only be an
estimate at that point — nothing has been sent anywhere yet — and the same estimate stands
for very different amounts of text depending on the language and on the target's
tokeniser. A rule reading "at least 2000 tokens" would fire at roughly 2000 characters of
Japanese and roughly 8000 of English, and nobody could say what it meant. A character
count means one thing. The rule tester shows both figures for a prompt you have in mind,
which is where a threshold is worked out.

### Routing by budget

A **Budget** condition asks how much has been used already, and can be written either
way round: *is under* a limit, or *has reached* it. It counts either **money** or
**requests**, and the choice sits next to the figure. Two rules are what express what
should happen on each side of it, and there is no separate "what to do when the budget
runs out" setting, because the order of the rules already says it:

```
1. Budget has room  ->  the expensive instance
2. (no conditions)  ->  the cheap instance
```

Delete the second rule, on a site that declines requests matching nothing, and the same
pair blocks instead of switching. A request stopped that way is stopped for good — see
*What a refusal is worth*.

**A budget in money is a budget at one provider.** Money is counted per provider, in
the currency that provider bills in, and is never added across providers, so a money
budget names the provider it counts and its amount is in that provider's currency. A
site whose budget is one figure for all its AI sets a budget at each provider it uses,
and adds them up itself; the dashboard's table by provider is what it adds up. The
currency comes from the provider's rates, so a budget naming a provider with no rates
has no currency to be in and cannot be measured, and the **Rates for budget conditions**
status check says so by name.

**Counting requests is the measure for everything that has no bill.** A model you run
yourself costs nothing to price and something to queue; a provider's free allowance is
often written as so many requests a month, not as an amount of money. A budget counted
in requests needs no rates at all, because requests are counted rather than worked out,
so it also works on a site that has entered none, and it counts every request whatever
provider answered it. Everything else about the condition is the same either way.

What is measured is **what the site paid for**. Requests covered by a key somebody
brought cost the site nothing and are left out of every budget, however large they are;
a limit on a brought key belongs to whoever brought it, and is always an amount of
money. The period is either the last *N*
days, counted from midnight today, or the current calendar month, and the figures come
from the summaries for what has been counted and from the detail records for what has
not, so a budget still works over periods the detail records no longer cover.

Two things are worth knowing before relying on one.

**A budget in money that nobody can measure is not a budget with room left in it.**
Costs are worked out from the rates entered on this site, so a request whose model has
no rate has no cost at all, and a site that has entered no rates spends nothing however
much it uses. A budget condition whose spending cannot be worked out is satisfied
**neither** way round, so rules carrying one never match and requests fall through to
whatever comes after them. The **Rates for budget conditions** status check says so when
a site routes by budget and its rates do not cover what is being used. None of this
applies to a budget counted in requests, and that check leaves those alone.

**A budget can only count the history the site still holds.** The history a budget
needs is protected from the purge, and a budget longer than the site keeps its
summaries is refused when it is written or switched on. None of that can bring back
history that had already gone: a site that ran with a short retention, lengthened it
and then set a thirty day budget has a budget counting thirty days over a table
holding three. The figure it gives is real and too small, and it corrects itself as
the missing days pass out of the period. The **Budget history** status check says when
a site is in that state, and from what date the counting really begins. It is never
turned into "unknown", because a budget that cannot be measured stops restricting
anything, which is the opposite of what somebody setting a limit wanted. What the check
reads is what the purge wrote down as it discarded each day, not the oldest row that
happens to be left: a site whose history has gone entirely looks exactly like a site
that has never used its AI, and those are not the same site. On a site that was already
running before that was written down, the starting point is whatever it can still show;
the check then says only that the period before it cannot be accounted for, without
claiming whether the usage was discarded or never happened.

**A limit is accurate to about a minute.** Adding up the history on every AI request
would be too much work for the path a request takes, so the figures are held briefly. A
burst of requests can therefore carry spending a little past a limit. Making the window
shorter would not fix it, because the request being weighed has not been paid for yet.
What changes the history as a whole — a currency correction, the daily summary — takes
effect on the next request instead: figures are held against the state of the history
they were read from, and are not used once it has moved on.

The part of a budget's period the daily task has already summarised does not change
until the task runs again, so it is not added up again on every request. The task works
it out for every course and every person once it has summarised the day, and a request
adds to it only what has not been summarised yet, which is mostly today. Both are kept in
Moodle's cache (the **Budget figures** and **Budget history** definitions of this plugin);
a site with APCu or another memory store can map them to it in the cache administration.

### Being told about a budget

A daily task looks at every budget the enabled rules set and at every limit somebody has
put on a key, and sends a message when one has been reached. There is also a warning
before it, at a share of the budget you choose — 80% by default, and nothing at all if
you set it to zero. Both settings are on the monitor page.

⚠ **Nothing is ever sent while a request is being handled.** A mail server having a bad
afternoon would otherwise become an AI having a bad afternoon.

Who hears what depends on what the budget is about:

| The budget is about | Who is told | What they are told |
| --- | --- | --- |
| The site | Administrators, and anybody who can see the usage monitor site-wide | The figures |
| A course | The same people | The figures, and which course |
| A course | Also the people who can see that course's usage | That it has been reached, and what happens next — **no figures** |
| A person | Administrators, and the person themselves | The figures to the administrators; to the person, that it has been reached |
| A key somebody brought | Whoever brought it | The figures. It is their key and their money |

Where a budget counts requests, the figures are counts rather than amounts of money,
and the course page shows them in full: what is kept from a teacher is what the site
pays, and how many requests their course made is that page's own subject.

The figure-free messages are not an oversight. What the site spends is not shown to
teachers anywhere in this plugin, and a notification must not be the way round that. The
share is shown instead, which is the part that is actually useful: a course page carries
a bar showing how much of its budget has gone, so a teacher can see where the course
stands without seeing what the site pays.

**Each threshold is said once.** It is remembered while it is over and forgotten when
the spending falls back below it, so a calendar budget speaks at most once a month
without anything here having to know what a month is. A rolling budget speaks again only
if it eases off and climbs back.

## Usage history

Moodle keeps its own record of every AI request, and on a site using the router that
record says `local_airouter` for all of them: `ai_action_register.provider` holds the
component of the provider the manager called, which is always this one. Moodle 5.3's
usage report shows the provider, the action, the tokens and whether it worked, and does
not show the model at all. So Moodle can tell you how much AI your site used, and not
where any of it went. That is what this history is for.

Each request is recorded with:

| | |
| --- | --- |
| Where it went | The provider instance, by id and by name, and which plugin it belongs to |
| What answered | The model the target reported |
| Why it went there | The rule that chose it, by id and by name |
| What it used | Prompt and generated tokens, as the target reported them |
| What it cost | Estimated from the rates below, worked out once and kept |
| What went wrong | Whether it was refused, whether every target failed, whether one threw, and how many were tried |

Names are stored next to ids on purpose. Rules get renamed and deleted and instances get
deleted, and a history reading "rule 14 sent this to instance 7" some months later is not
one anybody can use.

Requests the router **refused** are recorded too. How often a site turns requests down is
a number worth having, and it needs to be countable apart from targets breaking, which
means something quite different.

Failing to write this history never fails the request. A monitor is a tool for running a
site, not an obstacle on the path of every AI request.

### Requests and attempts

Underneath, the router writes two records as it goes: one row for the request, and
one row for every provider it asked on the request's behalf. The request row is
opened the moment the request arrives, before anything is decided about it,
and closed when the outcome is known. Each attempt row is written before the provider
is called and finished when it comes back -- or does not.

When the last provider has come back, its attempt and the request are closed together,
in one short database transaction, so that the database makes one write durable rather
than two. If that transaction cannot be committed, each is written again on its own, so
that what the provider reported using is not lost because the request could not be
closed. An attempt the router moves on from is closed on its own before the next
provider is asked.

When recording the end fails and the database connection can be brought back to a
normal state, the person still gets the provider's answer, and the provider is not asked
again. The endings are written again only once the database has confirmed that the
transaction was rolled back.

If the database cannot confirm even that -- the rollback fails too, on the connection the
whole request shares -- neither the answer nor the record is guaranteed. Nothing more is
written on that connection. The attempt and the request are left as started and open,
which the daily task later closes as lost and the status check reports, and what the
provider reported using goes to the developer log. Moodle stores its own record of the
action after the router, on the same connection, and that may fail as well, in which
case the person does not get the answer; at the end of the request Moodle rolls the
transaction back and writes to the error log that it had to. A paid provider may have
charged for a call whose answer never arrived: treat such a request as unfinished, with
its usage unknown, and bear that in mind before running it again. This is a database
failure to investigate and recover from.

These rows are only as durable as the transaction they are written in. If the code that
asks for AI already has a database transaction open, everything the router records --
including the rows written before a provider is called -- becomes visible to anyone
else only when that code commits, and disappears if it rolls back. The router does not
commit a transaction it did not open.

That last part is the point. A provider that takes the tokens and then answers a
failure has still charged for them, and a bill is made of calls, not of answers. So
every call leaves a row: the ones that answered, the ones that answered with nothing,
the ones that failed, the ones that threw. Each row says which provider, which model,
whose key it was made with, what it reported using and what that cost at the rates in
force at the time.

Three things a row can say are kept apart, because adding them together is wrong
whichever way it is done:

| | |
| --- | --- |
| Used nothing | Token counts of zero, marked as counts that can be believed |
| Did not say | No token counts, marked as counts nobody gave. Not zero |
| Nothing to price it by | A cost of nothing, which is not a cost of zero. A provider with a rate of zero is free, and free is a cost of zero |

An attempt that never comes back -- the process did not survive the call -- stays on
record as started, and is later closed as lost with its usage unknown. What the site
cannot know, the record does not pretend to know.

A write that fails is counted, so that a site can be told its history has holes rather
than find them by comparing a bill with a report.

An ending is written once. A retried process that sends the same ending again changes
nothing: the cost stays what it was at the rate of the day, and the day stays the day.
A request the router turns away at the door, before any provider is chosen, is
recorded too, as a declined request with no attempt.

Somebody who asks to be forgotten while their request is still in flight is forgotten:
the request and the attempts made so far go, and the attempts still to come are not
recorded, because a record nothing can trace back to a person is a record nobody can
delete for them.

Once a day, a task adds every request and attempt that has ended into a summary of
its day -- the day it ended, in the server's timezone -- and marks it as counted. What
has been counted is a fact on the row, not a watermark kept in the settings, so an
ending that arrives late is counted when it arrives, a run that is interrupted counts
nothing twice and nothing by half, and nothing is ever purged that has not been
counted, however old it is. The same task gives up on attempts that have been open for
six hours as lost, with their usage unknown, and closes their requests as failed.

The summary keeps requests and calls apart, keeps each payer's money apart, keeps each
currency apart, and says how many of the calls it added up had known token counts and
how many had a cost, so that a total can be read for what it covers. Detail rows are
kept for the number of days set on the usage page and then removed; summary rows are
kept for as long as the summary retention says, or for good.

The **Record gaps** status check says when the record has holes: writes that failed,
attempts given up as lost, and requests or attempts open for longer than any call
takes. A site comparing a provider's bill with these reports should read it first.

The site's usage dashboard reads these tables: the summary for what has been counted
and the detail for what has not, so that every finished request and call is counted
once, whichever day it ended on and whether or not the nightly task has reached it. A
request or call still in flight is not counted until it ends.

The report by person and the course page read them too. A person's individual
requests are listed with what every call made for them used, the failed calls
included, because that is what the person's request cost.

The budget bars on the course page and the budget conditions themselves still read
the older, one row per request record while they are moved across; until then the
two records are written side by side. The older record and its table go when the
last reader has moved.

**Requests and provider calls are counted separately.** A request that fell through to a
second provider is one request and two calls, and both were recorded: the call that
answered with nothing has a row of its own so that what it spent lands against the
provider and the key that spent it. Every count of requests on these screens counts
requests, so somebody who asked once appears once. What money there is, is the money of
the calls, so anything about cost -- the total, how much of a period a rate covered,
whether a budget has been reached -- is counted against the calls.

### The dashboard

**AI Router usage** (`/local/airouter/usage.php`) shows a period — the last 7, 30, 90
or 365 days — in a fixed shape: one time series, two breakdowns and one table.

It shows **one payer at a time**, and starts on the site's own key, which is what the site
actually spent. Requests paid for with keys people brought cost the site nothing, so a
cost that added them in would be nobody's expenditure — not the site's, and not any one
person's either. Switch **Paid for with** to see those instead, or to see everything
together. Whenever the page is leaving something out it says how much, so a filtered
screen is not mistaken for the whole of what the site did.

| | |
| --- | --- |
| By provider | One row per provider plugin: its currency, requests, provider calls, how many of them were priced, and the cost. **The table to add up by hand, if your budget is one figure for all AI** |
| Day by day | Requests and estimated cost, cost on its own axis, one cost line per provider |
| By which provider answered | Share of the requests each target took |
| By what was asked for | Share of the requests each action took |
| By model | Requests, prompt tokens, generated tokens and cost |

**Money is shown by provider and is never added across providers**, not even when two
bill in the same currency. Each provider's figure is in the currency that provider bills
in. Whether a site's budget is one figure for all its AI or one per provider differs from
site to site, so the page lays the providers side by side and leaves the adding to you.
The headline cost and every table follow the same rule; a cell for a period that used two
providers shows two figures.

Two figures sit beside them. **Requests that reached the router** compares this plugin's
history with Moodle's own register: if only part of the site's AI went through the router,
the rest was for actions not placed under it, which Moodle handled in its provider order
without asking the router. **Why requests failed** counts the failures by reason — refused,
every target failed, a target threw — and is drawn from the detail rows alone, so it
covers the period the detail still reaches back to and says so.

The shape is fixed on purpose. Answering each new question with another chart is how a
report grows without limit, and the questions a site owner has are how much is being used,
where it goes, what it costs and what is failing.

### Who used it

**AI Router usage by person** (`/local/airouter/userusage.php`, linked from the
dashboard) answers the question the dashboard deliberately does not: which named people
used the AI, how much, and what it cost. A site asked to account for its AI spending
eventually has to answer that, and the summaries keep enough to answer it long after the
detailed records have gone.

| | |
| --- | --- |
| By person | Requests, tokens, what the site's key paid and what the person's own key paid, kept apart, and each by provider in that provider's currency |
| One person, day by day | Goes back as far as the summaries do |
| One person's individual requests | When, where in Moodle, which provider and model. ⚠ Only as far back as the detailed records |
| Who has registered a key | Every brought key, whose it is, when it was registered and whether it last worked |

It also downloads as a spreadsheet, since a report like this is usually produced to be
read outside Moodle. Money in the file is a pair of columns per provider, named for the
provider and its currency, so that a column can be added up and nothing in the file adds
one provider's money to another's.

**Every cost on that page is an estimate**, worked out from the rates entered for this
site — including the costs shown against keys people brought, where it is an estimate of
what their own provider would have charged rather than a record of what it did. The page
says so above the figures, because a number quoted elsewhere without that sentence reads
as a bill.

Nothing about a key itself appears there, not even the last few characters shown to its
owner. An administrator asking who brings keys has no use for a fragment of somebody's
credential.

It is granted by `local/airouter:viewuserusage`, which **only managers have** by
default. The same figures narrowed to one course are a record of what each learner did,
and whether anybody at course level should hold that is a question for the site rather
than an assumption this plugin makes for it.

### What teachers see

A course with AI use through the router gets an **AI usage in this course** entry, which
shows the request count and which provider answered, for that course alone. It is
deliberately smaller than the site page: what a course used is a teacher's business, what
it cost the site is not, and who asked what is nobody's business on either page.

It is granted by `local/airouter:viewusage`, which editing teachers and managers have
by default.

### Rates

**AI Router rates** (`/local/airouter/rates.php`) is where the cost estimate comes
from. Rates belong to a provider plugin and a model rather than to an instance, since two
instances of the same provider are charged alike, and they are entered per million tokens
the way providers publish them. A rate with no model set covers anything from that
provider that has no rate of its own. Image responses carry no token counts, so images are
costed per image.

Each rate records the date it took effect. A request is costed with the rate in force when
it was made and keeps that figure, so adding a rate today leaves last month's numbers as
they were. A request no rate covers is recorded **without a cost** rather than with a cost
of zero, which would say it was free.

#### Providers that cost nothing

A model you run yourself has no bill to estimate, and there is a right way to say so:
give it a rate of **0**, with the model left empty so it covers everything that provider
answers with. No rate and a rate of zero are different statements — no rate means nobody
knows what a request cost, and zero means it was free — and several things downstream
depend on which one you meant:

- **Budget conditions in money can be measured.** Spending that cannot be worked out
  satisfies a budget condition neither way round, so on a site whose traffic is entirely
  unpriced those rules never match. With a rate of zero the spending is a known zero and
  budgets work again. (A budget counting requests rather than money needs none of this,
  and is often the more honest way to limit a model that is free but not unlimited.)
- **The status check stays quiet.** *Rates for budget conditions* counts requests that
  reached no rate, and a free provider entered as zero is priced, not missing.
- **Dashboards stop reporting a gap.** An unpriced request is shown as such, beside the
  figure, so that a total is never mistaken for the whole story.

**Token counts do not depend on any of this.** Every request records the tokens the
provider reported, priced or not, and the dashboards and reports add them up the same
way — so usage of a local model is measured in full even though it costs nothing. Moodle's
own Ollama provider reports token counts, and the router keeps whatever the provider
sent back.

#### Currency

Each rate is in the currency its provider bills in, and that currency is entered with
the rate: a provider billed in dollars has its rates in USD, one billed in yen has them
in JPY, and a site using both holds money in both. There is no site-wide currency, and a
cost is recorded in the currency of the rate that produced it.

A provider's currency does not change while the site is in use. It changes once, if at
all, when a provisional entry is corrected — rates typed in before anybody checked what
the provider bills in — and the correction is meant to leave nothing of the provisional
figure behind. So **saving a rate in a different currency from the provider's other
rates corrects the provider**: every rate of that provider is put in the new currency,
and **every cost already recorded for that provider is worked out again**, from what
each call used, at the rates now in force for its time, in the new currency. Attempts are
priced call by call; a summarised day is priced from the day's tokens and images at the
rate in force at the start of that day, which is as near as a day whose detail has been
purged can be brought. The page says what it changed. To correct a provisional rate,
**edit the provisional row** rather than adding a new rate from today: a new row leaves
the old figures in force for the old dates, and the recalculation would reproduce them.
Editing a rate's numbers without changing the currency recalculates nothing; that is an
ordinary revision, and last month keeps last month's figures.

Whether a save is a correction is decided at the moment of saving, under the same hold on
the record a correction takes. A rate page opened before somebody else corrected the
provider, and saved after, is therefore itself a correction back to the currency it
shows, and everything follows it; it never leaves one rate in one currency and the rest
in another. While the record is busy, a rate is not saved and the page says to try again.

A correction holds the record while it runs, and a call that ends while it is held is
recorded without a price rather than priced beside it: the price is worked out by the
next thing to hold the record, at the rates by then in force, which is the correction
itself once it has finished, the next call to end, or the daily summariser. Until then
the call reads as unpriced. The status check counts these among the record's gaps.

Nothing is converted between currencies: choosing an exchange rate source, a moment and
a rounding rule would lay a second layer of error over a figure that is already an
estimate. Figures from different providers are kept apart rather than added.

The token estimation ratios are on the same page, since they are also rates an
administrator maintains. Neither they nor the prices can change where a request goes.

### How long the history is kept

A scheduled task, **Summarise AI Router requests and attempts**, runs once a day. It
summarises each finished day into counts by person, course, action, target, model, payer,
wallet and currency, and then removes detail rows older than the retention period, which
is 90 days unless the site changes it.
Setting the retention to zero keeps everything.

The two halves are deliberately unequal. A detail row is close to personal information:
it says that a particular person asked for something, somewhere, at a time. A summary says
how much somebody used on a day and nothing about what they asked for, which is what a
site needs to account for its AI spending long after the detail has gone.

Summaries have a retention period of their own, which is **unlimited** by default. Set it
if your site would rather not keep person level history indefinitely. It cannot be shorter
than the detail period: reports read the summaries for the older part of any period, so
those days would simply read as empty, and nothing about the page would look wrong. A
summary is never removed while the day it describes still has detail rows.

Nothing is ever removed that has not been summarised first, whatever the retention period
says. A site whose cron has been stopped for a month catches up on the days it missed
before anything is deleted.

The summaries are kept at least as long as the furthest limit looks back. Budgets are
worked out from what is still stored, so throwing away history inside a budget's period
does not make the figure unknown -- it makes it smaller, and a limit that had been reached
comes back under the line. Limits people put on brought keys count, and so do budgets on
rules that have not started yet: a rule written today to begin next week looks back over
its whole period from its first day, and those days are in the table now. A retention
shorter than the longest budget is refused however it is saved, not only from the
settings screen; the rule screen refuses a budget longer than the summaries are kept for,
and switching such a rule back on is refused too. A retention and a budget saved at the
same moment are not both let through: one waits for the other and is checked against
what it saved. The purge itself follows the retention as set, so these refusals are what
keep a budget's history in place. A limit somebody puts on their own key is the one
exception: it is theirs to set and the retention is not, so it is saved, and they are
told that the figure against it is a floor.

The one way round the refusals is `config.php`, which can fix the retention beyond the
reach of any screen. That cannot be refused, so the **Budget history** check reports it
before anything has been thrown away under it, and every figure a short retention
produces is shown as a floor -- "at least", counted from the date the record begins --
rather than as a total. What none of this can do is bring back history that was already
gone when the budget was written, so lengthen the retention before writing a long budget
rather than after, and where that was not done, the same check says so rather than
leaving a figure that looks complete.

Days are the site's days, in the server timezone, and are counted through the calendar, so
the boundaries stay in place when a timezone changes offset.

### Privacy

The history names the user who made each request, so it is reported, exported and deleted
through Moodle's privacy API. **The prompt itself is never stored** — its length is used
to route the request and then forgotten, and what the AI answered is Moodle's record to
keep, not this plugin's.

The daily summaries name the person too, and are reported, exported and deleted the same
way. Removing somebody from them is a row deletion: everybody else's figures are untouched
and nothing is recomputed, which is what makes it affordable to name anybody at all.
Summaries written before this plugin recorded the person belong to **nobody** rather than
to user zero, and are shown that way.

They record **whether** a brought key paid, which is a category, but never **which** key:
a key somebody brought is one person's, so keeping its id would say who they were a second
time, in a column nothing needs.

## Bringing your own key

A key somebody brings pays for their own requests instead of the site paying. Two kinds
exist and they are not alike.

| | Whose it is | Who may set it | What it pays for |
| --- | --- | --- | --- |
| A personal key | The person who registered it | Anyone the site's policy admits | Their own requests |
| A course key | The course | Anyone who may edit the course (`local/airouter:managecoursekey`) | Every request made in that course |

A course key stays when the teacher who entered it stops teaching, and any teacher of that
course can replace it. That is the point of it: a key registered so that a class can use
AI should not stop working because one member of staff moved on.

A rule decides when a brought key is used, through its **Paid for with** setting. Holding
a key is then part of what the rule requires: where there is none the rule does not claim
the request and the next rule is considered, so

1. *Teachers → OpenAI, paid for with a key the person asking has brought*
2. *Teachers → Sakura AI Engine, paid for by the site*

reads as "their own key if they have one, ours otherwise".

### Who may bring one

**AI Router keys** (`/local/airouter/byok.php`) sets the policy: nobody, anybody with
an account, or only people matching conditions — a role held anywhere in the course tree,
membership of a cohort, or a profile field. The conditions can be combined either way,
because both readings are ordinary: "teachers, or anyone in the BYOK cohort" needs any one
of them, and "teachers who are also in that cohort" needs all of them.

The policy is checked when a key is registered **and again on every request that would use
one**, so tightening it stops the keys it no longer allows from being used. Those keys are
not deleted; they stop being used and remain their owners' to remove. The answer for each
person is held for a few minutes, because working it out means looking through role
assignments; a change to the policy itself is not held up by that and applies to the next
request, while a change in who holds which role is followed within those few minutes.

**A policy is only as good as the field it rests on.** Where a profile field is one the
person it describes can fill in — editable on their own profile, or asked for on the
registration form — the condition asks them whether they qualify and they answer. That is
right for something meant as a declaration, such as agreeing to pay for your own use, and
wrong for something meant as a fact about them, such as being staff.

Such fields are marked *(people can set this themselves)* in the chooser, and the *How
people qualify to bring a key* status check reports a policy that rests on one. For a
fact rather than a declaration, use a cohort, or a field only an administrator can
change: clear its "Who is this field visible to" setting, or lock it **and** keep it off
the registration form — a locked field is still typed in freely at registration, because
the signup form does not apply the lock.

### Where a key goes

Providers do not agree on what the field holding a key is called: Moodle's own say
`apikey`, Sakura AI Engine says `account_token`, another says `systemtoken` and ollama
takes none. Substituting "the apikey field" would work for some and silently do nothing
for others — the request would go out charged to the site while the person who brought a
key believed they were paying — so the same page asks an administrator to confirm the
field for each provider, offering a guess taken from the instance's own configuration.

A provider nobody has answered for cannot take a key.

What we have found so far, which the providers themselves may change:

| Provider | Where it ships | The field | Can take a brought key |
| --- | --- | --- | --- |
| OpenAI API Provider (`aiprovider_openai`) | Moodle 5.0 onwards | `apikey` | Yes |
| Azure AI Provider (`aiprovider_azureai`) | Moodle 5.0 onwards | `apikey` | Yes |
| Ollama API Provider (`aiprovider_ollama`) | Moodle 5.0 onwards | none | Takes no key |
| DeepSeek (`aiprovider_deepseek`) | Moodle 5.1 onwards | `apikey` | Yes |
| Google Gemini (`aiprovider_gemini`) | Moodle 5.2 onwards | `apikey` | Yes |
| AWS Bedrock (`aiprovider_awsbedrock`) | Moodle 5.2 onwards | `apikey` **and** `apisecret` | **No** — see below |
| Sakura AI Engine (`aiprovider_sakuraaiengine`) | Separately | `account_token` | Yes |
| Claude (`aiprovider_claude`) | Separately | `apikey` | Yes |

⚠ **A brought key cannot be used with AWS Bedrock in this version.** It authenticates
with an access key *and* a secret, and only one field is substituted, so the request
would carry somebody else's access key against the site's secret and be refused. Set
that provider to *This site allows no brought keys here*.

### What each provider allows

Beside the field, and separately from it, each provider carries what this site allows
there. The two are different kinds of statement: where a key goes is a fact about the
provider, and whether people may bring one is a decision of the site's.

| Setting | What happens |
| --- | --- |
| **People may bring their own** | The default. Keys can be registered here, and the site's own key works as before. |
| **This site allows no brought keys here** | The provider disappears from the key registration page, and a rule asking for a brought key here is never honoured. Requests still go through it on the site's own key. |
| **Only usable with a brought key** | The site's own key is refused here. A rule paying with it moves on to the next rule, the provider is never used as the default target or as a fallback behind another rule, and only requests carrying somebody's own key reach it. |

The last one is the way to keep a provider off the site's bill entirely. It is enforced
before the request leaves Moodle, so a request the site would have paid for moves on to
the next rule rather than spending a round trip being refused by the provider.

Rules that can never be honoured are called out in the rule list: one asking for a
brought key where nobody has said the field, and one paying with the site's key at a
provider reserved for brought keys.

Reserving a provider that takes no key is refused, because nothing would then be able to
reach it at all.

### What is stored

The key, encrypted with `\core\encryption`, and its last few characters in readable form
so that its owner can tell their keys apart. Nothing shows a key again, to anybody, and
nothing exports one. A key that is registered is changed through *Replace* beside it,
which is described next.

Beside the key's spending record is a keyed, one-way hash of every key that record has
held, so that a key can be recognised if it is entered again after being replaced or
removed. The hashes are made with a secret of the site's and cannot be turned back into
a key.

The encryption key lives in a file under the site data directory, not in the database.
**A site restored from a database backup without that file keeps every key and can read
none of them**, which the site status report says as an error rather than leaving it to be
discovered one failed request at a time.

### Replacing a key

A key that is registered is changed through **Replace** beside it in the table, not by
registering another for the same provider. Replacing raises a question the key itself
cannot answer: is the new key for the same provider account as the old one? A key renewed
at the provider is; a key from another account, or one somebody else pays for, is not.
The page asks, with nothing chosen in advance.

| Answer | What happens |
| --- | --- |
| The same account | What is counted against the key's limit goes on from where it is. The provider is billing the same account, and the limit is about that bill. |
| Another account | Counting starts from nothing. What the old key spent is not counted against the new one. |

Where the key carries a limit, the page also asks whether the limit stays. Entering the
same key again is recognised, and nothing is asked.

Removing a key does not remove what it spent. Register the same key again and the count
goes on from where it was; register another key and the page asks whether it is for the
account that was here before, offering the record of each key that was removed, or a
fresh start.

Behind this is a record the plugin calls a wallet: what a key's spending is counted
against. Every key has one. A key rotated within an account keeps its wallet, a key for
another account gets a new one, and a wallet outlives its key. A wallet keeps a keyed,
one-way hash of every key it has held, which is how a key is recognised as the wallet's
after the key itself has gone, and which cannot be turned back into the key. A key the
wallets recognise goes back to its wallet whichever way it is entered and whatever is
answered.

Wallets belong to one owner — a person, or a course — at one provider. The same key
registered by two people, or as both a course key and somebody's own key, has a wallet
for each, and each counts only its owner's use: a limit on your own key is about your own
spending, and a figure that included other people's use would show you what they spent.
Where several people are meant to use one key and have it counted together, register it
as the course key. What the provider account spent in total is on the provider's bill.

The answers are about the key that was on the screen. A course key can be looked after by
more than one teacher, and when somebody else replaces or removes the key while the page
is open, the answers no longer describe the key that is there, so **the new key is not
saved**: the page says the key changed and asks for it to be replaced again. The one
exception is a key the wallets recognise, which needs no answers. In the same way,
registering a key for a provider that somebody else has just given a key sends the
person to **Replace** instead. Changes to one person's or one course's keys are made one
at a time; one that finds another still being saved after a few seconds says so and saves
nothing.

### What happens when a brought key is used

| Situation | What the router does |
| --- | --- |
| No key is registered for the person, or for the course | The rule does not claim the request. This is an ordinary state, not a failure, and the next rule is considered |
| The rule asks for a course key and the request came from outside any course | The same: there is no course to charge |
| The person may no longer bring a key | The same. Their key is not deleted; it stops being used |
| The key has reached the limit its owner set on it | The same. Not a failure either: somebody set themselves a limit and reached it, and refusing the request would punish them for being careful |
| Nobody has said which field this provider's key goes in | The same. Delegating without putting the key anywhere would charge the site while the person believed they were paying |
| The key is registered and cannot be decrypted | **Stops the request** and says so. This is a fault in the site, and carrying on to the next rule would quietly move the cost onto the site |
| The provider refuses the key (401 or 403) | **Stops the request** and tells the person whose key it was. Nobody else's key would change the answer, and they are the only one who can put it right |
| The provider fails for some other reason | Tries the other instances the same person or course has a key for, and no others |

That last line is the rule the whole feature turns on: **while a brought key is paying,
the fallback chain never leaves it.** The default delegation target does not stand behind
a request somebody asked to pay for themselves.

Requests are recorded with who paid and which key, and the dashboard shows one payer at a
time, so what the site spent stays separate from what people spent themselves.

### Limiting your own key

Whoever brought a key can put a limit on it, on the same page, either for the calendar
month or for a rolling number of days. It is theirs to set: an administrator limiting it
would be limiting somebody else's money, and the site limits its own spending with budget
conditions instead.

When the limit is reached the key simply stops being used, and requests follow the site's
rules to wherever they go next. Nothing is refused and nothing is reported as broken,
which is why the key page says so plainly — the other way to read a key that quietly
stopped working is that the key is broken.

The limit is an amount in the currency the key's provider bills in, which is the
currency of that provider's rates. Until a rate is entered for the provider, the limit
has no currency and nothing can be measured against it, and the key goes on being used.

Two things behave the way they do on purpose.

**Replacing a key asks whether the period goes on.** The spending is counted by the
account the provider bills, not by the row in the database, and only the owner knows
whether a new key is for the same account, so the page asks rather than assuming (see
*Replacing a key* above). A limit saved from a screen opened before the key was moved to
another account is refused, and the owner told, since it was decided about the account
that was on the screen; a limit saved while the key was only renewed lands as usual.

**A limit nobody can measure does not stop anything.** Spending is estimated from the
rates the site has entered, so a site that has entered none measures nothing; a budget
condition treats that as a reason not to route, but a limit on somebody's own key treats
it as a reason to carry on. The opposite would let a site's missing rates silently
disable every key it holds. Spending recorded in a currency the limit is not written in
counts as unmeasurable for the same reason: the limit is a figure in whatever the site
currency is now, nothing here converts between currencies, and 1000 yen is neither above
nor below a limit of 500 dollars. The key page shows the figure in the currency it was
recorded in and leaves out the bar, rather than relabelling it as the current one.

⚠ The figures are what the site's rates say the requests would have cost, not what the
provider actually billed. The real figure is on the provider's own bill.

### Testing a key

Testing sends one very short request to the provider and reports whether the key was
accepted. The provider may charge a small amount for it, which the screen says, and
nothing is ever tested unless somebody asks for it.

Only three answers are possible: accepted, refused, or no conclusion. A provider that
cannot be reached, or that fails for its own reasons, reports the third — telling somebody
their key is wrong when the provider is simply having a bad day sends them looking for a
problem that is not there.

## Routing an action Moodle does not define

The four actions Moodle ships all take text in. Nothing in core asks an AI to
listen to a recording, or to look at a picture. Routing here is written against
actions rather than against those four, so a provider plugin and a small plugin
defining the action are enough to route something else.

Two are included as proof: `local_aimedia` defines **transcribe audio** and **ask
about a picture**, and the Sakura AI Engine provider offers both. Rules, budgets, brought keys and the usage
history all apply to it, because none of them know which actions exist.

An action defined outside core is offered **only while its plugin is installed**. The
router reads the list afresh for every request, so an action installed after the router
was set up can be placed under it on *Actions the AI Router must answer* straight away.

⚠ Three places in `core_ai\manager` build an action's class name from core's own
namespace, so an action living anywhere else is not found there. The one that
shows is the enable and disable switch on the provider settings screen: it writes
to a key nothing reads, so **an action Moodle did not define cannot be switched
off there**. It arrives switched on and works. Stop it with a routing rule
instead. This has been written up for core.

⚠ A transcription carries no token counts, so it cannot be costed from the rate
table and shows as unknown spending. A budget counted in requests measures it,
which is also the shape a provider's free allowance usually takes. Asking about a
picture is different: a vision model answers in text and counts tokens, so that
one is costed like any other request.

## Behaviour when a target fails

The router tries its candidates in order and returns the first usable answer.

| What the target does | What the router does |
| --- | --- |
| Answers | Passes the answer through unchanged, including the model, finish reason and token counts |
| Reports an error | Tries the next candidate. If none is left, reports the last status code, so a 429 stays a 429 |
| Throws | Tries the next candidate. Moodle does not catch exceptions on the way to a provider, and the providers that ship with Moodle catch Guzzle's `RequestException` but not `ConnectException`, so an unreachable endpoint would otherwise end the whole request |
| Answers with nothing | Tries the next candidate, because an empty answer shown as though it had worked is worse than a failure |
| Answers with nothing after running out of tokens | Reports it rather than retrying. Another target would spend its budget the same way, and shortening the input is something the user can act on |
| Refuses a brought key | Reports it rather than retrying. See *Bringing your own key* |

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

### Reaching a model you run yourself

Nothing to do with this plugin, but it catches everybody who tries, so it is worth
saying here: **Moodle refuses outgoing requests to private networks and to unusual ports
by default.** Under *Site administration → Security → HTTP security*:

- **Blocked hosts** ships with `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12` and
  `192.168.0.0/16`, so a model on your own network is unreachable;
- **Allowed ports** ships with `80` and `443` only, so Ollama's `11434` is refused even
  once the address is allowed.

Both have to be adjusted before any AI provider — this one, or Moodle's own Ollama
provider — can reach a self-hosted model.

⚠ The failure is unhelpful. A blocked request produces a developer coding error rather
than a message saying the address is blocked, for every provider Moodle ships. Requests
that go through the router are caught and reported as an ordinary failure; requests that
do not go through it are not. This has been written up for a bug report.

## Installation

Copy this directory into your Moodle installation, then visit *Site administration →
Notifications* (or run `php admin/cli/upgrade.php`) to complete the install. Where it
goes depends on the release, because the web root moved under `public/` in 5.1:

| Release | Path |
| --- | --- |
| Moodle 5.0 | `<moodleroot>/local/airouter/` |
| Moodle 5.1 and later | `<moodleroot>/public/local/airouter/` |

> Moodle caches the list of present plugins, so if you copy the files with `rsync` or
> similar, run `php admin/cli/purge_caches.php` **before** the upgrade — otherwise Moodle
> will not detect the new plugin.

A site that has the connector `aiprovider_router` from an earlier build: see
*Upgrading from an earlier build* at the top of this page.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
