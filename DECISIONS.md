# Design decisions

A record of the decisions behind Pickle Rota and why they were made. The app grew out of a single-group scheduler built for Slapton Village Hall (preserved in `legacy/`); most of these decisions were earned through live use, and several through bugs found on real Friday nights.

## 1. Single-file vanilla JS app, PHP + JSON backend

No framework, no build step, no database. The whole client is one HTML file; the backend is one PHP file writing JSON files with `flock`. Reasons: deploys to any shared host by copying files; nothing to compile; trivially debuggable; and the load profile (a handful of phones polling every 3 seconds) is tiny. A database would add operational burden for zero benefit at this scale.

## 2. Control model: one organiser at a time, via a rotating token

Anyone can watch; only one device can edit. Entering the group password issues a fresh random token and stores it as the current controller - which silently invalidates whoever held control before. This gives "control passes to whoever last entered the password" with no accounts, no sessions, and no clashing edits. `hand over` (release) clears the controller. The token is only ever sent to the device that claimed it; `read` responses report `youControl` as a boolean instead.

In the multi-tenant version passwords are user-chosen, so they are stored hashed (`password_hash`/`password_verify`) with a 300ms brake on failed attempts. This is deliberately a shared-secret model, not real authentication: anyone told the password can take control. That is the right trade-off for a social sports group.

## 3. Sync model: polling + monotonic revision counter

Clients poll every 3 seconds. The server increments `rev` on every write (claim/save/release). Clients track `localRev` and treat the server as truth for viewers; the controller is the source of truth while it holds control and only pushes.

Two guards exist because of a real race we hit in production:

- **Token-snapshot guard**: a poll's reply is discarded if the local token changed while the request was in flight (a claim or handover happened mid-request). Without this, a poll fired just before "Take control" could come back carrying the old token's view and wipe out freshly-claimed control.
- **Revision guard**: any reply with `rev` lower than `localRev` is discarded as stale. This defends against cached or delayed responses (seen with hosting-level caching) reverting newer state.

Both are cheap and together they made takeover reliable. Keep them.

## 4. Aggressive cache-busting everywhere

Safari and shared-host caches repeatedly served stale copies of both the app and API responses during development. Mitigations, all kept deliberately:

- Every API call appends `?_={timestamp}` and uses `fetch(..., {cache:"no-store"})`.
- The HTML carries no-cache meta tags (helpful, not sufficient on Safari).
- The app displays a visible version tag (`APP_VERSION`, bottom of page) so you can instantly tell which build a device is running - this resolved more "mystery bugs" than anything else.
- When embedding via iframe, bump a `?v=` query on the src after every deploy.

## 5. Iframe embedding: report height, never scroll internally

The app is designed to be embedded (originally in a WordPress page). Lessons baked in:

- The app posts its height to the parent (`postMessage`) so the iframe can auto-size; the parent page then does all scrolling. A fixed-height iframe traps touch scrolling on mobile.
- Height must be measured from the **content elements' bounding boxes**, not `document.body.scrollHeight`: Safari stretches the body to fill the iframe, so a body-based measurement plus any safety margin creates a feedback loop where the frame grows forever.
- When embedded (`window.parent !== window`), fixed-position UI is switched to in-flow, and no centred pop-ups are used anywhere: `position:fixed` centres against the full (tall) iframe, not the visible screen. All "modal" interactions are inline (the confirm step, the guide panel).

## 6. Mobile resilience: re-sync on wake

iOS freezes pages on sleep/background; timers stop and the UI can appear dead on return. The app listens for `visibilitychange`, `pageshow` and `focus` and immediately re-renders and re-syncs. This removed the "buttons don't work until you refresh" failure.

## 7. Fairness algorithm (default mode)

Selection: sort active players by fewest games, then longest wait, then random jitter; take four. "Ease in late arrivals" (default on) adds a soft rule: players who played the immediately previous game are deprioritised when enough rested players exist - so a latecomer catches up via play-rest-play rather than several games back to back.

Team split: of the three ways to pair four players, choose the one minimising `10 × repeat-partnerships + repeat-opponents + small random`. Partner variety is weighted well above opponent variety on purpose.

Bookkeeping: pair counts are keyed on sorted id pairs; games are committed only when the next game is drawn, so the in-progress match is never double-counted and re-shuffles before play cost nothing.

## 8. "Prioritise mixing" (variety mode)

Strict fairness plus the ease-in rule creates cliques at even group sizes: with 8 players the same two foursomes alternate all night (simulated and confirmed; with 12 players, three fixed foursomes). Variety mode replaces sorting with search: score **every possible foursome** and play the cheapest, where

    cost = 1.0 × (sum of pair play-together counts among the four)
         + 2.0 × (each player's games above the group minimum)
         + 0.75 × (each player who played the previous game, if ease-in is on)
         - 0.6 × (each player's consecutive games benched, capped at 5)
         + random × 0.3

The WAIT term exists because without it, simulation showed players could sit out 3-5 consecutive games at odd counts; with it, worst-case is 2 (3 at eleven players) while game counts still never drift more than 1 apart and nearly every game is a fresh foursome. Pools larger than 18 are pre-trimmed to the fairest 12 before enumeration to bound the search.

If you tune the weights, re-run the simulations (see the repo history / recreate with a small Node harness that drives `buildCurrent`/`commitCurrent` with fake players) - every weight here was chosen against measured behaviour, not intuition.

## 9. Gender modes

Three modes: Open (default), Mixed pairs (each team one ♂ one ♀), and M/W games (alternating men's and women's games). Players carry a badge cycling `– → ♂ → ♀`; **unset ("–") players are wildcards eligible for any slot**, so nobody is benched for lack of a symbol. When numbers make a mode impossible (five men, one woman), the app falls back to an open draw for that game and tells the organiser - people playing beats rules holding. Known consequence, documented in the in-app guide: in M/W mode a sex that cannot field four simply gets no games until numbers change.

## 10. Manual overrides must update the model

Players rearranging teams on court without telling the app silently corrupts the pairing history (the app records the draw, not reality - this produced a real "we already played together" complaint). Hence tap-to-swap on the match card, including swapping with benched players. The rule generalises: any manual override the organiser can physically do should be expressible in the UI so the fairness bookkeeping stays truthful.

## 11. Undo and history

Undo is a local (per-controller-session) stack of full-state snapshots taken before every mutation, capped at 25. It is not synced and is cleared whenever a server sync replaces local state - undo reverts *your* recent actions, nothing subtler. The "Previous games" log stores player *names*, not ids, so history survives roster removals.

## 12. Multi-tenant design (this repo)

- One group = one JSON file (`data/{slug}.json`) holding name, password hash, control token, timestamps and the scheduler state. No shared database, no cross-group queries needed.
- Slugs are derived from the group name (lowercased, hyphenated, max 30 chars) with `-2`, `-3`... on collision. Slugs are guessable by design: the group page without the password is the spectator view.
- `lastUsed` is touched only by claim and save - actually running games - not by reads, so polling spectators do not keep a dead group alive and reads stay write-free.
- Cleanup: groups unused for 90 days are deleted, both opportunistically on every group creation and via `cleanup.php?key=...` for a weekly cron. Deletion is unceremonious; the data is a rota, not a record.
- Abuse limits: minimum password length, group-name length cap, a hard cap on total groups (default 500) and a state-size cap per group. These are speed bumps, not security theatre - see ROADMAP for rate limiting.
- `data/` lives outside the webroot (the subdomain's document root is `public/`), with a deny-all `.htaccess` inside it as defence in depth for anyone who deploys differently.

## 13. Things deliberately left out

Scores and winner-stays-on (the founding goal is equal games and social mixing, not competition), multiple courts, accounts, and any client-side framework. All are possible later; none earn their complexity yet.
