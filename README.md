# AI Router for Moodle (`aiprovider_router`)

An AI **provider** plugin for the Moodle AI subsystem that acts as a *router*: instead of
talking to a model itself, it decides — per request — which configured AI provider should
handle the call, and delegates to it.

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

A router instance has four settings, on the provider instance form.

| Setting | Description |
| --- | --- |
| Operating mode | *Router only* expects every AI request to come through the router, which needs to be first in the provider order. *Alongside other providers* leaves requests the router declines to whichever provider comes next. |
| When no rule matches | *Send it to the default delegation target*, or *decline the request*. Declining hands the request back to Moodle, which tries the next AI provider in the site order: alongside other providers the site carries on as before, while in router only mode there is no next provider and the request stops. Declining is also how a site keeps AI spending to the cases its rules describe. The default follows the operating mode. |
| Default delegation target | The provider instance that handles a request when no rule picks one, and the one a request falls back to if the target a rule chose fails. Not needed on a site that routes entirely by rule and declines the rest; otherwise the router reports itself as not configured, so core skips it rather than handing it requests it cannot serve. |
| Make refusals final | On by default. Stops Moodle trying another provider when the router turns a request down because a budget has been reached, because a key somebody brought cannot be used, or because nothing is configured to handle the request. See *What a refusal is worth* below. |

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
| What happens when the AI Router says no | Which providers come after the router and could answer a request it turned down. A warning where *Make refusals final* is off, because a budget or a brought key would then be bypassed; otherwise a statement of what is behind the router. |
| Leftover entries in the provider order | The order still names instances that have been deleted. Moving providers up and down works on positions in that list, so leftovers can make reordering appear to do nothing. |
| Rule delegation targets | A rule names a provider instance that no longer exists. Requests matching it fall through to the next rule. |
| Actions the router instance can carry | An action the router offers is not configured on its instance, so requests for it never reach the router. This happens when a plugin defining an action is installed after the router instance was made: the action list is read fresh every time, the instance's configuration is written once. ⚠ The provider settings screen cannot put it right for an action outside core, so recreating the instance is the fix. |

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

## Rules

Rules are managed at **Routing rules** (`/ai/provider/router/rules.php`), linked from the
router's own settings form and from the site status report. Like the provider order page,
it is not in the admin tree: Moodle never reads an `aiprovider` plugin's `settings.php`,
so there is no admin tree entry to hang it on.

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

**Test the rules** (`/ai/provider/router/ruletest.php`) asks for a course, a user, an
action, a placement and a prompt, and shows what each rule did with that request: matched,
skipped, which conditions were not satisfied, or not reached because something above it
matched first. Nothing is sent to any provider and nothing is recorded; the rules are
evaluated by exactly the code a real request uses.

It reports the prompt's length in characters, which is what prompt length conditions
compare against, and an estimated token count beneath it. Tokens are the unit cost and
context windows are thought about in, so the estimate is there to help you settle on a
character threshold — but no rule depends on it.

The estimate uses one character per token for CJK text and four for everything else. This
version has no screen for changing those ratios; they are read from the plugin
configuration settings `tokenratiocjk` and `tokenratioother`, which for now means the
command line:

```
php admin/cli/cfg.php --component=aiprovider_router --name=tokenratiocjk --set=1.2
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

Moodle tries each AI provider in the site order and stops at the first one that
succeeds. A provider reports a failure, and Moodle reads every failure the same way: as
that provider being unable to help. There is no way for a provider to say *this request
has been answered and the answer is no*.

That distinction is the whole of what this plugin does. A request the router turned down
because a budget had been reached, or because the key somebody brought could not be
used, would otherwise be offered to the next provider in the order and answered on the
site's own key. The limit would hold only until somebody asked twice.

**Make refusals final** closes that. Where it is on — the default — a refusal of that
kind raises an error, which is the only thing that stops Moodle's loop. What it costs is
worth knowing:

- the person making the request sees an error message rather than a quiet failure;
- Moodle does not write the request to its own AI log (`ai_action_register`). The
  router's own usage records are written before the error is raised, so the reports in
  this plugin, and the budgets that read them, are unaffected;
- a placement that catches the error can show it however it likes. Moodle's own
  placements do not, so the message appears as an error.

Not every failure is treated this way, and the line matters:

| The router says | Is it final? |
| --- | --- |
| A rule fitted this request and its budget has been reached | **Yes.** A spending limit that can be bypassed is not a limit |
| A key somebody brought cannot be read, or was refused, or every instance they hold a key for has been tried | **Yes.** Carrying on would move the cost onto the site, which is the opposite of what was asked |
| Nothing is configured to handle this request | **Yes.** A half configured site should be visibly half configured rather than quietly answered by something else |
| No rule claimed this request | **No.** This is the router saying the request was not its business, which is exactly the point of running it alongside other providers. Moodle carrying on is correct |
| A target was unreachable, broke, or returned nothing usable | **No.** That is what the fallback is for |

Turning the setting off restores Moodle's ordinary behaviour throughout.

**This is not a complete answer, and the plugin does not pretend otherwise.** It stops
a refusal from being mistaken for a fault; it cannot remove the other providers from
Moodle's list. The *What happens when the AI Router says no* status check reports which
providers sit behind the router, so that a site owner can see what would happen if the
setting were off. A request for a proper way to say this has been raised with Moodle.

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
pair blocks instead of switching. A request stopped that way is stopped for good, and
not handed to the next provider in the site order — see *What a refusal is worth*.

**Counting requests is the measure for everything that has no bill.** A model you run
yourself costs nothing to price and something to queue; a provider's free allowance is
often written as so many requests a month, not as an amount of money. A budget counted
in requests needs no rates at all, because requests are counted rather than worked out,
so it also works on a site that has entered none. Everything else about the condition is
the same either way, including which requests are counted.

What is measured is **what the site paid for**. Requests covered by a key somebody
brought cost the site nothing and are left out of every budget, however large they are;
a limit on a brought key belongs to whoever brought it, and is always an amount of
money. The period is either the last *N*
days, counted from midnight today, or the current calendar month, and the figures come
from the daily summaries as far as they reach and from the detail rows beyond them, so a
budget still works over periods the detail rows no longer cover.

Two things are worth knowing before relying on one.

**A budget in money that nobody can measure is not a budget with room left in it.**
Costs are worked out from the rates entered on this site, so a request whose model has
no rate has no cost at all, and a site that has entered no rates spends nothing however
much it uses. A budget condition whose spending cannot be worked out is satisfied
**neither** way round, so rules carrying one never match and requests fall through to
whatever comes after them. The **Rates for budget conditions** status check says so when
a site routes by budget and its rates do not cover what is being used. None of this
applies to a budget counted in requests, and that check leaves those alone.

**A limit is accurate to about a minute.** Adding up the history on every AI request
would be too much work for the path a request takes, so the figures are held briefly. A
burst of requests can therefore carry spending a little past a limit. Making the window
shorter would not fix it, because the request being weighed has not been paid for yet.

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
record says `aiprovider_router` for all of them: `ai_action_register.provider` holds the
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

### The dashboard

**AI Router usage** (`/ai/provider/router/usage.php`) shows a period — the last 7, 30, 90
or 365 days — in a fixed shape: one time series, two breakdowns and one table.

It shows **one payer at a time**, and starts on the site's own key, which is what the site
actually spent. Requests paid for with keys people brought cost the site nothing, so a
cost that added them in would be nobody's expenditure — not the site's, and not any one
person's either. Switch **Paid for with** to see those instead, or to see everything
together. Whenever the page is leaving something out it says how much, so a filtered
screen is not mistaken for the whole of what the site did.

| | |
| --- | --- |
| Day by day | Requests and estimated cost, cost on its own axis |
| By which provider answered | Share of the requests each target took |
| By what was asked for | Share of the requests each action took |
| By model | Requests, prompt tokens, generated tokens and cost |

Two figures sit beside them. **Requests that reached the router** compares this plugin's
history with Moodle's own register: if only part of the site's AI went through the router,
the rest reached another provider first, which is a matter of the provider order rather
than of any rule here. **Why requests failed** counts the failures by reason — refused,
every target failed, a target threw — and is drawn from the detail rows alone, so it
covers the period the detail still reaches back to and says so.

The shape is fixed on purpose. Answering each new question with another chart is how a
report grows without limit, and the questions a site owner has are how much is being used,
where it goes, what it costs and what is failing.

### Who used it

**AI Router usage by person** (`/ai/provider/router/userusage.php`, linked from the
dashboard) answers the question the dashboard deliberately does not: which named people
used the AI, how much, and what it cost. A site asked to account for its AI spending
eventually has to answer that, and the summaries keep enough to answer it long after the
detailed records have gone.

| | |
| --- | --- |
| By person | Requests, tokens, what the site's key paid and what the person's own key paid, kept apart |
| One person, day by day | Goes back as far as the summaries do |
| One person's individual requests | When, where in Moodle, which provider and model. ⚠ Only as far back as the detailed records |
| Who has registered a key | Every brought key, whose it is, when it was registered and whether it last worked |

It also downloads as a spreadsheet, since a report like this is usually produced to be
read outside Moodle.

**Every cost on that page is an estimate**, worked out from the rates entered for this
site — including the costs shown against keys people brought, where it is an estimate of
what their own provider would have charged rather than a record of what it did. The page
says so above the figures, because a number quoted elsewhere without that sentence reads
as a bill.

Nothing about a key itself appears there, not even the last few characters shown to its
owner. An administrator asking who brings keys has no use for a fragment of somebody's
credential.

It is granted by `aiprovider/router:viewuserusage`, which **only managers have** by
default. The same figures narrowed to one course are a record of what each learner did,
and whether anybody at course level should hold that is a question for the site rather
than an assumption this plugin makes for it.

### What teachers see

A course with AI use through the router gets an **AI usage in this course** entry, which
shows the request count and which provider answered, for that course alone. It is
deliberately smaller than the site page: what a course used is a teacher's business, what
it cost the site is not, and who asked what is nobody's business on either page.

It is granted by `aiprovider/router:viewusage`, which editing teachers and managers have
by default.

### Rates

**AI Router rates** (`/ai/provider/router/rates.php`) is where the cost estimate comes
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

One currency applies site-wide and nothing is converted: choosing an exchange rate source,
a moment and a rounding rule would lay a second layer of error over a figure that is
already an estimate. To work in yen, set the currency to JPY and enter the rates in yen.

The token estimation ratios are on the same page, since they are also rates an
administrator maintains. Neither they nor the prices can change where a request goes.

### How long the history is kept

A scheduled task, **Summarise AI Router usage**, runs once a day. It summarises each
finished day into counts by person, course, action, target, model, payer and currency,
and then removes detail rows older than the retention period, which is 90 days unless the
site changes it.
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
| A course key | The course | Anyone who may edit the course (`aiprovider/router:managecoursekey`) | Every request made in that course |

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

**AI Router keys** (`/ai/provider/router/byok.php`) sets the policy: nobody, anybody with
an account, or only people matching conditions — a role held anywhere in the course tree,
membership of a cohort, or a profile field. The conditions can be combined either way,
because both readings are ordinary: "teachers, or anyone in the BYOK cohort" needs any one
of them, and "teachers who are also in that cohort" needs all of them.

The policy is checked when a key is registered **and again on every request that would use
one**, so tightening it stops the keys it no longer allows from being used. Those keys are
not deleted; they stop being used and remain their owners' to remove.

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
nothing exports one. Registering a key for the same provider replaces it.

The encryption key lives in a file under the site data directory, not in the database.
**A site restored from a database backup without that file keeps every key and can read
none of them**, which the site status report says as an error rather than leaving it to be
discovered one failed request at a time.

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

Two things behave the way they do on purpose.

**Replacing a key does not start the period again.** The spending is counted by who
brought the key and what it is for, not by the row in the database, because the provider
carries on billing the same account either way.

**A limit nobody can measure does not stop anything.** Spending is estimated from the
rates the site has entered, so a site that has entered none measures nothing; a budget
condition treats that as a reason not to route, but a limit on somebody's own key treats
it as a reason to carry on. The opposite would let a site's missing rates silently
disable every key it holds.

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

An action defined outside core is offered **only while its plugin is installed**.
Naming one that is absent makes the provider settings screen fatal, because that
screen asks each action for its own name.

⚠ **Install the plugin defining the action before creating the router instance.**
An instance is configured for the actions that existed when it was made, and nothing
revisits that later, so a router that predates the action lists it and never receives
it. The *Actions the router instance can carry* status check reports this.

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
| Moodle 5.0 | `<moodleroot>/ai/provider/router/` |
| Moodle 5.1 and later | `<moodleroot>/public/ai/provider/router/` |

> Moodle caches the list of present plugins, so if you copy the files with `rsync` or
> similar, run `php admin/cli/purge_caches.php` **before** the upgrade — otherwise Moodle
> will not detect the new plugin.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
