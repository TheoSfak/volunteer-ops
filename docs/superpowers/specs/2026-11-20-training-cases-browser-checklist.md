# Training cases (v3.505.0) - browser and device checklist

What the automated run (PHPUnit, `node --test`) cannot do. Tick each item on a real drill. Two devices or profiles are needed (a commander on a desktop, a volunteer on a real phone), plus a second volunteer of the same team.

## Ιατρική αναθεώρηση (read this first)

**The three starter cases (chest pain, unresponsive adult, heat collapse) are unreviewed first-aid drafts, written without clinical review. They carry `reviewed = 0` and must stay that way until a medically qualified person has gone through them. No volunteer should be given an unreviewed case**: until then use them only for a staff dry run, and expect the warning at staging and the «Ανέλεγκτο» badge on the plan card.

Open questions for the medical reviewer:

1. **Chest-pain case**
   - The aspirin stance (given or withheld, and under what conditions).
   - The casualty's own nitrates are rated best: is that right, and what must be checked first (blood pressure, other medicines)?
   - Sugar water is rated harmful: confirm.
   - Legs raised is rated harmful: confirm for this presentation.
   - An AED at a mountain refuge: is fetching one a sensible choice, and when?
   - 166 versus 112 / mountain rescue for a remote trail: which number should the case teach?
   - The private-car offer: acceptable, or harmful?
2. **Unresponsive adult case**
   - The recovery position for an unresponsive adult with shallow breathing, pulse 140, blood pressure below 90 and an unwitnessed fall (possible spinal injury): right call, or should the case teach something else?
   - The order of the actions around the call for help.
3. **Heat collapse case**
   - The cooling sites offered (neck, armpits, groin): right, and enough?
   - Immersion in cool water is not offered as a choice: should it be?
   - When to place the call (before or after starting to cool).
   - An antipyretic and a blanket are rated harmful: confirm.
4. **All three cases**
   - Several choices are rated "acceptable" although they skip a key action (they are not wrong, but they leave something out): review each one and decide whether it should be "acceptable" or "harmful", and whether its feedback says what is missing.
   - Check every feedback and closing text for wording, units and local protocol (Greek emergency numbers, EKAB).

After review: an admin sets «Ελέγχθηκε» in the library (see the library section); the flag resets whenever the case is edited. A drill that staged the case earlier keeps the snapshot's own flag («Ανέλεγκτο» follows the snapshot, not the library row).

## 1. Set up a drill and stage a case

- [ ] Create a drill (training mission) and a scenario with a phase.
- [ ] In the dispatch window, pick a starter case at a point on the map for one team; the unreviewed warning shows.
- [ ] «Τυχαίο» draws a case, shows which one came up, and can draw again; the filters (type, difficulty, language) narrow the pool.
- [ ] Dispatch window layout with the case picker: the extra controls wrap acceptably on a phone-width admin screen (no clipped buttons, no horizontal scroll).
- [ ] The picker is hidden or disabled for area orders, and is not offered at all in a real (non-training) mission; for the real mission the endpoint also refuses (try the staging request by hand).
- [ ] The plan card shows «Περιστατικό: ...» and the «Ανέλεγκτο» badge for an unreviewed case; after the case is reviewed in the library, a newly staged item loses the badge but the earlier one keeps it.
- [ ] The case text renders as text: an `<b>` typed in the editor shows literally everywhere (plan card, player, command card, print).
- [ ] Play the phase; the order is sent to the team.

## 2. The volunteer on a real phone

- [ ] The order arrives; there is no case button before the team has arrived.
- [ ] «Έφτασα» (or GPS arrival) records the arrival; the case button appears; a teammate gets the arrival notification.
- [ ] Player overlay layout on the phone: banner, «Σταμάτα», SOS, story, findings table, buttons large enough, nothing hidden behind the browser bars, long text scrolls.
- [ ] z-index: the overlay is above Bootstrap modals, toasts and the page's own SOS during a case; a toast arriving mid-case does not cover the answer buttons.
- [ ] SOS inside the overlay: a tap does nothing, a 1-second hold sends it; the keyboard hold (Space or Enter held for 1 second on the focused SOS button) also works.
- [ ] Focus: the overlay takes focus on open, Tab stays inside it, focus returns to the dispatch card on close.
- [ ] Android back button closes the overlay (or asks) without leaving the page or losing the run; reopening resumes at the current step.
- [ ] Answer every step: feedback after each choice, no going back, branching works, «Συνέχεια» goes on.
- [ ] The end screen shows the score and label, the volunteer's path next to the best path, and the closing lessons.
- [ ] Finishing does not complete the order: «Ολοκληρώθηκε» is still the team's step.
- [ ] A second volunteer of the same team opens the case and gets their own run.

## 3. Offline

- [ ] Open the case once online (so the document is cached), then switch the phone to offline mode: the case still opens and plays.
- [ ] Answer offline, go back online: the queued answers flush by themselves and appear in command's «Περιστατικά» card.
- [ ] Close the mission, then flush queued answers: they stay queued (not dropped) and the volunteer is not shown a scary error.
- [ ] Let the session expire, then flush: the answers stay queued and flush after logging in again.
- [ ] A reply with code `refused` (for example the volunteer was removed from the audience) is the only case where a queued answer is dropped.
- [ ] Shared phone with two volunteers: volunteer A answers offline, logs out, volunteer B logs in on the same phone; B does not see or send A's answers or A's cached case (`wr_case_doc_<user>_<placement>`, `wr_case_answers_<user>_<placement>` in local storage are per viewer).

## 4. Command: the «Περιστατικά» card

- [ ] The card renders in the Action Room for command staff only (a volunteer does not see it).
- [ ] Placement: in the Console layout and when its section is collapsed.
- [ ] It refreshes about every 20 seconds while open; open details (a run, the per-step counts) stay open across a refresh.
- [ ] One row per placed case with each volunteer's state (not started, in progress at step N, finished with score and time); a click shows the run step by step.
- [ ] The per-step breakdown shows how many chose each option.

## 5. Training record and certificate

- [ ] The volunteer's training record shows «Περιστατικά: n, βαθμολογία x%» for the drill (score = sum of points over sum of maximum, finished runs only), in the profile and in the admin's volunteer view.
- [ ] The printed participation certificate is unchanged and prints correctly.
- [ ] A drill with no finished case shows no case line.

## 6. The library page and the editor (system admin)

- [ ] The library lists the three starters, unreviewed and marked "starter".
- [ ] Duplicate a starter, then edit the copy; the starter itself offers no edit.
- [ ] Validation errors in plain language for: a loop, an unreachable step, a step with no `best`, fewer than 2 choices, a missing `next` target.
- [ ] A refused save re-draws the form with what was typed.
- [ ] The editor's «Δοκιμή» plays the case and saves no run.
- [ ] Mark reviewed: the review modal shows the confirmation box and only then sets the flag; editing the case resets it.
- [ ] Export downloads a JSON file (one case, and a pack).
- [ ] Import: the section collapses and opens, the preview shows before anything is saved, confirm saves, a slug clash renames the case, a file over the limits (200000 bytes, 50 cases) is refused with a clear message.
- [ ] Archive hides a case but a drill that staged it earlier still plays it.
- [ ] Mobile layout of the library page and the editor is usable.

## 7. Hostinger, after update.php

- [ ] `SHOW TABLES` lists `training_cases`, `mission_case_placements`, `mission_case_runs`, `mission_case_answers`.
- [ ] `SHOW CREATE TABLE mission_scenario_items` shows `payload` as MEDIUMTEXT with its long COMMENT intact.
- [ ] Open the library page once, then `SELECT slug, reviewed FROM training_cases` shows the three starters, all `reviewed = 0`.
- [ ] The trigger `trg_pr_set_training` is still present (`SHOW TRIGGERS LIKE 'participation_requests'`).
- [ ] The service worker picked up `vo-v3.505.0` (hard reload once; DevTools, Application, Cache Storage).
- [ ] No `[training-cases]` lines in the PHP error log after a full run-through.
