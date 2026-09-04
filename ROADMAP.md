# Roadmap / ideas

Deliberately unbuilt so far (see DECISIONS.md section 13 for the philosophy). Roughly in order of likely value:

- Per-group settings page for the organiser: change password, rename group, delete group now
- Rate limiting on create and claim (per-IP token bucket in a small state file) - current limits are speed bumps only
- Optional scores: winner-stays-on and Americano-style points modes
- Multiple courts: draw N simultaneous matches from one pool (the variety scorer generalises naturally)
- Export/print the session history
- Nicer landing page with screenshots once there are real groups to show
- A tiny Node test harness in-repo for the draw algorithm (the tuning simulations currently live only in the project history)
- Accessibility pass (focus order, ARIA on the custom toggles)
- i18n if it ever escapes the South Hams
