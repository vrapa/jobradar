# Multi-step source searches

Category counters: `step_displayed_count` counts alerts/category entries, not their linked offers. Required `step_related_count` counts processed linked results separately, cumulatively, at least as many as imported details and at most the source limit per category step. Initialize at zero only before any linked result is processed. Recover older missing counters from evidence, not an invented zero. Linked results do not consume later keyword budgets. Reaching the linked-results safety bound with unseen results means partial coverage, not end of results. Other modes must not send this field. `detail_opened_count` still records actual opened details for the source.

Routine batch boundaries use `pause` with the complete current checkpoint, not terminal `finish partial`. Pause keeps the same request eligible for recovery in the next authorized wake. Stop Chrome and do not claim again in this wake. The partial/waiting rules below refer to actual blockers or exhausted scope limits, not a normal five-minute batch boundary.

Read this when a source has nonempty `search_plan.steps`. The request pins this plan; never replace it with the newest source settings. Start the source normally, then follow the listed steps in order. Resume the first nonterminal step; completed steps stay complete.

`mode=keywords` means enter the saved query in the portal search. `mode=category` means browse the saved category; the query describes the selection scope, not a search-field value. `mode=skill` means select the saved tool/skill in the portal's supported filter. Other filters may include eligibility/review constraints (remote_from, exclusions, paid_unlock), not necessarily native UI controls. Verify actual active controls and do not invent unsupported filters. If a configured control is unavailable, report partial/error rather than silently broadening the search.

After starting the source, initialize the current step with `checkpoint`: ordinary `completed_unit` plus `step_key`, `step_status=running`, and cumulative `step_displayed_count` (zero only at initial start, not an assertion of no results). Keep public URL/page and the last processed position in checkpoint. Record only processed result positions, even when a page renders more results than the remaining limit. Stop reading additional result entries at the saved boundary.

The source limit is shared across all steps' primary entries: category entries for category mode, offer results for keyword/skill mode. Repeated offers seen in another query consume a position; linked category results use the separate counter described above. Reopening checkpointed positions for recovery does not consume them again. Import deduplication is separate: use the original URL and `searchStep` equal to the running step key in each import. Keep the current step running while importing and assessing details.

Complete a step by checkpoint with `step_status=complete`, cumulative `step_displayed_count`, and `step_completion_reason`:
- `end_of_results`: visibly verified end, explained in completed_unit.
- `step_limit`: its saved limit reached.
- `source_limit`: the shared limit reached.

Do not finish a step merely because a page or work batch ended. Save running progress with pause; actual blockers use partial/waiting_for_login as appropriate. If the shared limit is exhausted at a completed checkpoint, the server marks remaining steps `skipped_limit`; they were not searched and must not be described as searched. All bounded work can then finish complete only with accurate source totals and the existing required profile/rules/checkpoint evidence. `displayed_count` in the final source result is the sum of step counts. A skipped-limit step contributes zero budget consumption, not an observed empty search.

The API checks order, monotonic counts, per-step/shared limits, step ownership and completion. Retry a lost response with the original key, payload and lease. After claiming a new lease, consult stored state and use new keys for new work; never replay old events under the new lease. Never lower a count or switch steps to get around a rejection.
