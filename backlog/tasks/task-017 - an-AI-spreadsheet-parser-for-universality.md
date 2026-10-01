---
id: TASK-017
title: an AI spreadsheet parser for universality?
status: Done
assignee: []
created_date: '2026-10-01 03:55'
updated_date: '2026-10-01 05:11'
labels:
  - templates
  - onboarding
  - ai
dependencies: []
ordinal: 17000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
A new business signed up and landed on `/onboarding`: a page asking it to email us example bills of
materials and suppliers, quoting a maximum of two business days. Nothing else in the product was
reachable - `BusinessReadyMiddleware` held every page a customer would use - until somebody here read
each spreadsheet, filled in the templates form, tested it and pressed Activate. Activation also
started the trial and sent the only welcome email, which is why the trial had to be started there:
thirty days that began while the customer could not import a thing was not thirty days.

The reading and the testing were already automated and already sat behind the admin templates screen.
`TemplateProposalService` reads a sheet and fills the form in; `TemplateTestService` runs the real
importer over the proposal, classifies every extracted row against the business's catalogue and plan,
and has a second model read the result back; `TemplateTestChecklist` names each check and decides
whether a save is allowed; `TemplateTestCertificate` makes that gate server-side rather than a
disabled button. What was missing was letting a customer's own upload trigger them.

So an upload that matches none of the business's templates now writes its own, against the same gate,
and the onboarding state is gone.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 An upload matching no template is described, tested against itself and recorded as a live template if it passes - `App\Services\TemplateLearningService`
- [x] #2 The gate is the one an admin's template passes: `TemplateTestChecklist::passed()`, over the same extraction. A failing proposal is not saved live and not saved inactive
- [x] #3 The next upload of a learned format is an ordinary import - no model call, no second template describing the same table
- [x] #4 A failure keeps the spreadsheet, the proposal and every check (`template_learning_attempts`), emails every admin, and tells the customer we have the file rather than asking them to email it
- [x] #5 The admin templates screen leads with unresolved attempts; opening one fills the form in with the proposal that failed, beside a download of the same file
- [x] #6 Recording a template closes the attempts it now reads, re-matching each stored sample the way an upload is matched
- [x] #7 A machine-written template carries `generated_by_ai` with no `reviewed_at` and is listed as "not reviewed" - a reading list, not a gate
- [x] #8 Bounded: an hourly per-business attempt ceiling, an off switch, and a retention window after which the customer's spreadsheet is deleted and only the account of the failure is kept
- [x] #9 Onboarding is deprecated: `admin_setup_complete`, `BusinessReadyMiddleware`, `/onboarding`, Activate and Deactivate are gone, and the trial runs from registration
<!-- AC:END -->

## Implementation Notes
<!-- SECTION:NOTES:BEGIN -->
Both upload paths learn. `ProjectController::store` is the one that mattered most - the new project
modal is where a customer's very first spreadsheet arrives, and a file matching nothing used to fail
validation there before the project was even created, with "did the template change?" said to a
business for which no template had ever existed. `ProductController::store` (the BOM modal) is the
other. `TemplateService::readFiles()` now separates "unmatched" - a spreadsheet we read fine and have
no template for, which is learnable - from "unreadable", which is still refused outright.

Learning runs outside any transaction: it makes two OpenAI calls, and a transaction held open across
an outbound HTTP call pins a database connection to somebody else's API.

No certificate on this path, deliberately. `TemplateTestCertificate` exists because the admin screen's
test and save are two requests and the save cannot take the browser's word for it. Here they are one
call and the values tested are the values saved, which is all a certificate proves.

What was deliberately not done:

- No queue. The wait is one spinner on an upload the customer is already waiting on, and a job would
  need a status surface, a worker and a polling UI to show the same thing. `TemplateLearningService`
  has a single entry point, so making it dispatchable later is a small change.
- No approval step before a learned template goes live. Gating it on an admin reading it would put the
  customer back to waiting on us, which is the thing being removed. `reviewed_at` records that
  somebody looked, and changes nothing about importing.
- No welcome email at registration. The verification email is unchanged and the customer lands on a
  working board, so there is no waiting state to be welcomed out of. `WelcomeActivatedUserEmail` and
  the resend button survive as the way to get somebody in whose verification mail never arrived.

Worth knowing: `OPENAI_API_KEY` is now load-bearing in practice. Without it every unrecognised upload
is a failed attempt on the admin screen - which is the queue this emptied, so the onboarding backlog
comes back silently if the key is ever missing in production.

Related: TASK-003 wants the passing test retained as evidence against a template version. The attempts
table now retains the *failing* ones; the passing side is still thrown away by design.
<!-- SECTION:NOTES:END -->
