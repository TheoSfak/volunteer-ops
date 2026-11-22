# Training case library: sources and review status

The starter cases in `includes/training-cases/*.json` are practice material for volunteers. **None of them has been reviewed by a medically qualified person.** They load as unreviewed (`reviewed = 0`), the random draw skips them unless the commander asks for «και μη ελεγμένα», and the library marks them «μη ελεγμένο». A reviewer marks a case «ελεγμένο» in the library only after reading it.

Each case follows the Greek emergency numbers used in the app (166 EKAB, 112) and is written for a lay rescuer, not a clinician. Cases model typical incident patterns found in first aid and wilderness medicine guidance. They are not reconstructions of named real incidents.

## Sources used

| Source | Used for |
|---|---|
| ERC (European Resuscitation Council) Guidelines 2021 and 2025, first aid and basic life support chapters | choking, bleeding and tourniquet, drowning, burns, seizures, stroke (FAST), anaphylaxis, CPR sequence, helmet removal, paediatric choking and CPR |
| Resuscitation Council UK (anaphylaxis 2021, first aid 2025 summary of the ERC update) | adrenaline auto-injector, second dose after 5 minutes without improvement, 5 rescue breaths first for drowning |
| Wilderness Medical Society, accidental hypothermia guideline (2019) | gentle handling, horizontal position, insulation, 60 second check, CPR in hypothermia, no oral intake when drowsy |
| Wilderness Medical Society, lightning injury guidance | reverse triage, no residual charge, leave exposed ground |
| START triage (CHEMM, US HHS, and public EMS protocols) | mass casualty triage (walking, respirations over 30, perfusion, commands) |
| British Burn Association and Red Cross burn cooling advice | cooling for about 20 minutes (at least 10), no ice, no creams, cover loosely |
| Viper bite first aid guidance (UK adder guidance, Patient.info, UCSD horned viper protocol, a Greek mountain trail guide) | no incision, no ice, no tourniquet, immobilise, hospital transfer |
| WHO psychological first aid, WMS hypothermia | approach to a missing person with dementia |

## What was checked and what was not

Checked against a web search during preparation (secondary summaries and guideline pages, not the full guideline texts):

- Burn cooling duration: sources agree on cooling with cool running water, about 20 minutes, minimum about 10, and warn against cooling much longer in children. The ERC 2025 figure itself was **not** confirmed.
- Anaphylaxis second dose after 5 minutes: confirmed in Resuscitation Council UK material, not in the ERC text.
- Drowning with 5 initial rescue breaths: confirmed through the UK summary of the ERC 2025 first aid update.
- Choking sequence (back blows, then abdominal thrusts, alternating): confirmed. The ERC 2025 choking text was a draft in the pages found. The final version should be read before relying on the wording.
- Viper bite: sources agree on no incision, no ice, no tourniquet, immobilise the limb, go to hospital. They **disagree** on a pressure bandage, so the cases do not teach one.
- START: the perfusion test is a radial pulse or capillary refill over 2 seconds. The cases use both.
- Hypothermia: gentle handling, horizontal position, 60 second check before CPR, no futility signs.

Written from general first aid knowledge and not re-verified in that search: stroke FAST and no aspirin, seizure first aid, hypoglycaemia (15 to 20 g of fast sugar), in-line stabilisation after a fall, motorcycle helmet, carbon monoxide, electrical injury, asthma, infant choking and CPR, smoke inhalation, dementia and missing person care.

## Before the cases are marked reviewed

A reviewer with first aid or medical training should read each case and check the best answer, the «ικανοποιητικό» answers and every «επιβλαβές» feedback against the current ERC and local EKAB guidance, and edit the file if a statement is wrong. Editing a starter file resets its review mark on the next load, so the review is of the content as shipped.
