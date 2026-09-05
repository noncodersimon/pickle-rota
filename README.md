# Pickle Rota

Fair, sociable rotation for one-court pickleball groups. Everyone gets the same number of games, partners keep changing, latecomers get worked in gently - and the whole group can watch the live draw on their phones. Free, no accounts, runs on any shared PHP host.

Live at: https://pickle-rota.digitelos.co.uk (a [Digitelos](https://digitelos.co.uk) project)

## How it works

Anyone creates a group on the landing page (a name and an organiser password) and gets two links: an organiser link (`/g/your-group`) and a read-only player link (`/g/your-group?view=1`). One person at a time holds control - entering the password takes it, and passes it if someone else enters it later. Everyone else sees the live match, who's sitting out, game counts, and the history.

The scheduler draws doubles matches that keep game counts even, avoid repeat partners and opponents, bring late arrivals in at the group's pace rather than letting them catch up, and (optionally) prioritise fresh foursomes over strictly equal counts. There are mixed-pairs and alternating men's/women's modes, tap-to-swap for manual overrides, an undo stack, a confirm step on advancing games, and a built-in guide behind the ? button.

## Repository layout

- `public/` - the deployable site: landing page, the app (`app.html`, served at `/g/{slug}`), backend (`api.php`), per-group manifest, cron cleanup endpoint, icons, `.htaccess` rewrites
- `data/` - live group JSON files (gitignored; keep outside the webroot - see deployment guide)
- `docs/` - DECISIONS.md (why everything is the way it is - read this first), DEPLOYMENT.md, ROADMAP.md
- `legacy/slapton-single-instance/` - the original single-group version this grew from, kept for reference

## Deploying and contributing

See `docs/DEPLOYMENT.md` for the shared-hosting setup (point the subdomain document root at `public/`, set the cleanup key, optionally add a weekly cron). Before changing the draw algorithm, read `docs/DECISIONS.md` sections 7-8 - the weights were tuned against simulations, not vibes.

## Credits

Built by Simon at Digitelos with Claude (Anthropic), battle-tested by the Slapton pickleball group.
