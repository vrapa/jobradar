# Prepare access before checking offers

This phase is explicitly requested by `task.prepare_access`. It prepares access only; never call start, import, assessment or finish in this phase.

Read the available computer-use instructions. Use Chrome and a session/group named `💼 Práce` via its supported sessionName option. Reuse existing matching task tabs when possible. If grouping is not available, keep separate Chrome tabs and report that limitation; do not claim a group was created. Never close unrelated tabs.

For each assigned source without a stored `access_preparations` result:
- Open its configured URL and observe visible access state using the existing browser session. Do not run searches, paginate, or read/import offer details.
- If signed in or the assigned landing page is public, report `available`. This only describes observed landing-page access; private details may still require login later.
- If login is needed, open the visible login link and leave that tab ready. Use the browser's documented `markHandoff()` on newly opened tabs so they remain open for the user when this wake ends. Keep other prepared source tabs open using that same supported handoff mechanism. Existing browser authentication/SSO redirects can complete normally. Do not read, enter or submit credentials, inspect password manager contents, trigger credential autofill, handle MFA, bypass CAPTCHA or accept terms. The user completes those steps directly in Chrome.
- Report `login_required`, `blocked` or `error` where observed. Call `prepare_access` with the assigned source_id, stable idempotency_key and payload containing only `status`. No login URLs, credentials, cookies, screenshots or free-form sensitive text are stored in this operation.

After all sources are reported, the API returns `waiting_for_login` and releases the lease. Give one concise summary naming sources that require attention and link to the existing JobRadar request detail. Ask the user to complete login in Chrome and press “Přihlášení mám připravené – pokračovat v kontrole”. Even when every landing page is available, wait for that confirmation. End the wake; no polling or new request creation.

After confirmation, the next claim resumes the same request. Recheck access before normal traversal; never treat preparation as evidence that offers were checked. A later detail login gate follows the existing waiting-for-login workflow.
