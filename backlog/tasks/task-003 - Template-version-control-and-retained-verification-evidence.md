---
id: TASK-003
title: Template version control and retained verification evidence
status: To Do
assignee: []
created_date: '2026-10-01 01:55'
labels:
  - iso-9001
  - clause-8.5.6
  - clause-7.5.2
  - templates
  - major
dependencies: []
priority: high
type: feature
ordinal: 3000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Templates decide how a customer BOM is read, and recording one is a live change that needs no deploy. The table carries `active` and nothing else: no version, no approver, no effective date, and until recently no record of what it used to say.

The test gate is real and enforced server-side - TemplateTestCertificate signs the extraction fields under the application key, so a template cannot be saved without a passing test - and it then throws the evidence away by design: "nothing about a test that was run and not saved is worth keeping". For a developer that is correct reasoning. For ISO 9001 8.5.6 it is the exact opposite of the requirement, which is to retain the results of the review of changes and the person who authorised them. The control exists and the proof of it does not, which is the hardest kind of gap to argue out of in an audit.

PR #50 put Template under RecordsChanges, so what a template used to say is now recorded. What is still missing is version identity, approval, and the retained test result.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A template carries a version that moves when any extraction field changes
- [ ] #2 The passing test that gated the save is retained against that version: the sample, what it extracted, who ran it, when
- [ ] #3 An effective-from date, so a BOM imported last month can be read against the version that was live then
- [ ] #4 The imports a given template version produced are identifiable from the template side
- [ ] #5 Activating or deactivating a template records who did it
<!-- AC:END -->
