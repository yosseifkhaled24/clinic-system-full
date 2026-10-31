# Clinic Chatbot — Medical Guidance Reference

> **Status: requires clinician review before production use.** The guidance
> below is a conservative, source-documented knowledge base assembled from
> reputable public patient-facing sources. A qualified clinician must review
> the wording, the escalation thresholds, and the Arabic translations before
> this assistant is exposed to real patients.

_Last reviewed: 2026-10-27 (see `FirstAidGuidance::REVIEWED_AT`)._

## 1. Safety architecture (what is deterministic vs. prompt-enforced)

| Layer | Implementation | Deterministic? |
|---|---|---|
| Emergency short-circuit (EN/AR) | `app/Services/EmergencyDetector.php` (`assess()`) — runs **before** any Gemini call; fixed bilingual reply, no questions, no appointment suggestions | Yes |
| Same-day "urgent" tier | `EmergencyDetector::urgent()` — injects a prompt notice only; never overrides emergencies | Yes (detection) |
| First-aid knowledge base | `app/Services/FirstAidGuidance.php` — injected into the prompt **only** when the message matches a covered situation | Yes (selection) |
| Specialty grounding | `app/Services/ConcernSpecialtyMapper.php` — intersected with real `doctors.specialty` values | Yes |
| PHI redaction | `app/Services/PHIRedactor.php` — emails + ≥9-digit runs stripped from message + history | Yes |
| Triage behavior (≤3 questions, no diagnosis, no blanket refusal, urgency wording) | System-instruction modules in `GeminiChatbotService` | Prompt-enforced (LLM output cannot be guaranteed) |

**Keyword matching is a safety net, not a triage system.** It cannot judge
context, severity, or novel phrasing. The prompt explicitly tells the model
this and requires it to apply its own emergency guidance and to recommend
professional evaluation whenever urgency cannot be judged. False positives
on the emergency tier are accepted (harmless: "call emergency services");
the urgent tier is deliberately conservative to avoid noise.

## 2. First-aid knowledge base — categories, sources, review dates

All entries are conservative by construction: general comfort/basic-aid
measures only — **no medication, no dosages, no substances to ingest or
apply, no claims about the cause** — and each ends with an explicit
"when to seek professional care" limit. Sources are the patient-facing
guidance pages below; review dates live in code
(`FirstAidGuidance::REVIEWED_AT`, bumped on each re-validation).

| Category | Key | Sources reviewed (2026-10-27) |
|---|---|---|
| Minor burns / scalds | `minor_burns` | American Red Cross First Aid/CPR/AED manual (burns); NHS inform — Burns and scalds: first aid; Mayo Clinic — Burns: first aid |
| Nosebleed | `nosebleed` | NHS inform — Nosebleed (epistaxis); Mayo Clinic — Nosebleeds: first aid; American Red Cross First Aid manual |
| Minor cuts / grazes | `minor_wound` | NHS inform — Cuts and grazes; Mayo Clinic — Cuts and scrapes: first aid; American Red Cross First Aid manual |
| Sprains / strains | `sprain_strain` | NHS inform — Sprains and strains; Mayo Clinic — Sprains and strains: first aid; American Red Cross First Aid manual |
| Mild dehydration | `mild_dehydration` | WHO — Oral rehydration salts / dehydration care; NHS inform — Dehydration; Mayo Clinic — Dehydration: first aid |
| Mild fever | `mild_fever` | NHS inform — Fever in adults; Mayo Clinic — Fever: first aid; WHO — fever care guidance |
| Mild headache | `mild_headache` | NHS inform — Headache; Mayo Clinic — Headache: when to seek care; WHO — Headache disorder guidance (basic triage) |
| Insect bites / stings | `insect_bite_sting` | American Red Cross First Aid manual; NHS inform — Insect bites and stings; Mayo Clinic — Bee sting: first aid |

When a message does **not** match a covered situation, no KB block is
injected and the model is instructed (in the standing first-aid rules) to
say reliable guidance is unavailable and recommend professional evaluation
instead of improvising.

### Urgency escalation tiers used by the KB

- **Emergency** (deterministic short-circuit): airway/breathing/swallowing
  difficulty (with or without facial swelling — swelling + airway difficulty
  is the anaphylaxis combination rule), chest pain, severe bleeding,
  seizure, stroke signs, unconsciousness, choking, poisoning/overdose,
  self-harm, severe burns, head injury, blue lips, sudden worst headache.
- **Urgent, same-day** (prompt notice): high/persistent fever, severe
  abdominal pain, blood in urine or stool, pregnancy bleeding, persistent
  vomiting, confusion, sudden vision loss. Sourced from the general
  red-flag patterns in the same NHS/Mayo/WHO patient-facing pages.

## 3. Review & maintenance process

1. `FirstAidGuidance::ENTRIES` is the single source of truth for guidance
   wording, keywords, sources; `EmergencyDetector::RED_FLAGS` /
   `URGENT_SIGNS` for escalation detection; `ConcernSpecialtyMapper::CATEGORIES`
   for navigation keywords.
2. On each review: re-check every source URL/page, update wording if the
   source changed, bump `FirstAidGuidance::REVIEWED_AT`, and note the
   reviewer in the commit/PR.
3. Automated guards: `tests/Feature/Api/ChatbotHealthNavigationTest.php`
   scans every entry for banned medication/dosage terms (EN + AR), verifies
   sources + review dates exist, and covers emergency/urgent positive AND
   negative cases per category.
4. New categories are added by appending an entry with keywords (EN+AR),
   conservative guidance, a seek-care limit, and sources — no code changes
   elsewhere are needed.

## 4. Known limitations / clinician-review checklist

- [ ] Clinical wording of all guidance bullets and escalation thresholds
      (EN + AR) — confirm they match your institution's protocols.
- [ ] Arabic medical phrasing — reviewed by a native-speaking clinician.
- [ ] Emergency phrase list completeness for the local population's
      common expressions (extend `RED_FLAGS` as needed).
- [ ] Local emergency number guidance: the reply says "your local emergency
      number"; confirm the intended number/branding for your market.
- [ ] Confirm the urgent-tier list matches your same-day-care policy.
- [ ] Medication questions are always declined and redirected — verify no
      regional OTC advice expectations conflict with policy.
- [ ] The assistant never claims availability (weekly hours only) — verify
      wording matches the booking system's real capabilities.
