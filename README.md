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

Still to come, and not in this version:

- **BYOK (bring your own key)** — per-user and per-course API keys, stored encrypted with
  `\core\encryption`, with a policy controlling who may bring one. Keys can be
  registered and tested; **no rule can send a request with one yet**
- **Budget conditions**, which depend on the dashboards above

## Settings

A router instance has three settings, on the provider instance form.

| Setting | Description |
| --- | --- |
| Operating mode | *Router only* expects every AI request to come through the router, which needs to be first in the provider order. *Alongside other providers* leaves requests the router declines to whichever provider comes next. |
| When no rule matches | *Send it to the default delegation target*, or *decline the request*. Declining hands the request back to Moodle, which tries the next AI provider in the site order: alongside other providers the site carries on as before, while in router only mode there is no next provider and the request stops. Declining is also how a site keeps AI spending to the cases its rules describe. The default follows the operating mode. |
| Default delegation target | The provider instance that handles a request when no rule picks one, and the one a request falls back to if the target a rule chose fails. Not needed on a site that routes entirely by rule and declines the rest; otherwise the router reports itself as not configured, so core skips it rather than handing it requests it cannot serve. |

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
| Rule delegation targets | A rule names a provider instance that no longer exists. Requests matching it fall through to the next rule. |

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
where it delegates, and buttons to reorder, copy, switch off or delete. Two things are
called out there, because both are easy to create and hard to spot afterwards:

- a rule with **no conditions**, which takes every request that reaches it;
- any rule **below** such a rule, which nothing can reach.

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
| A rule matches but its target has been deleted, switched off, or cannot perform the action | Moves on to the next rule. There is nothing to carry the rule out with, and stopping there would strand the request |
| No rule matches | Follows the *When no rule matches* setting |
| *When no rule matches* is set to decline | The default delegation target is not used as a fallback either. An administrator who keeps unclaimed requests away from a provider does not expect a failure to send one there |

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

One currency applies site-wide and nothing is converted: choosing an exchange rate source,
a moment and a rounding rule would lay a second layer of error over a figure that is
already an estimate. To work in yen, set the currency to JPY and enter the rates in yen.

The token estimation ratios are on the same page, since they are also rates an
administrator maintains. Neither they nor the prices can change where a request goes.

### How long the history is kept

A scheduled task, **Summarise AI Router usage**, runs once a day. It summarises each
finished day into counts by course, action, target, model and currency, and then removes
detail rows older than the retention period, which is 90 days unless the site changes it.
Setting the retention to zero keeps everything.

The two halves are deliberately unequal. A detail row is close to personal information:
it says that a particular person asked for something, somewhere, at a time. The summary
is not — it names nobody — so it is kept indefinitely and is what a report covering last
year is drawn from.

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

The daily summaries hold no user ids at all. That is what lets them be kept: a summary
that named people would have to be rebuilt every time somebody exercised their right to
be forgotten, and a history rebuilt on demand is not a history.

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

**Nothing here routes anything yet.** Keys can be registered, tested and removed, and the
policy decides who may bring one, but no rule can be told to use a key. That arrives with
the rest of this feature.

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

### What is stored

The key, encrypted with `\core\encryption`, and its last few characters in readable form
so that its owner can tell their keys apart. Nothing shows a key again, to anybody, and
nothing exports one. Registering a key for the same provider replaces it.

The encryption key lives in a file under the site data directory, not in the database.
**A site restored from a database backup without that file keeps every key and can read
none of them**, which the site status report says as an error rather than leaving it to be
discovered one failed request at a time.

### Testing a key

Testing sends one very short request to the provider and reports whether the key was
accepted. The provider may charge a small amount for it, which the screen says, and
nothing is ever tested unless somebody asks for it.

Only three answers are possible: accepted, refused, or no conclusion. A provider that
cannot be reached, or that fails for its own reasons, reports the third — telling somebody
their key is wrong when the provider is simply having a bad day sends them looking for a
problem that is not there.

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
