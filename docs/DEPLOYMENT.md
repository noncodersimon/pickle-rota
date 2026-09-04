# Deployment guide

Target: shared PHP hosting (built for Krystal; anything with PHP 8+, mod_rewrite/.htaccess support and file write permission works).

## First deploy

1. Create the subdomain (e.g. `pickle.digitelos.co.uk`) and point its **document root at the repo's `public/` folder**. This is the important bit: it keeps `data/` outside the webroot so group files (which contain password hashes and control tokens) can never be fetched directly. The deny-all `.htaccess` inside `data/` is only a backstop.
2. Upload the repository (everything except `.git` if you prefer).
3. Permissions: files 644, folders 755. `data/` must be writable by PHP - if group creation returns a store error, set `data/` to 775.
4. Edit `public/cleanup.php` and change `CLEANUP_KEY` to something random.
5. Smoke test:
   - Open the landing page, create a test group, check you land on `/g/test-...` and the app connects (no red status bar).
   - Take control with your password on one device, then take over from a second device - the first should drop to view-only.
   - Fetch `https://your-domain/data/` and confirm it does NOT list or serve files.
   - Fetch `/cleanup.php?key=WRONG` (expect 403) and with the right key (expect a JSON summary).

## Scheduled cleanup (optional but tidy)

Groups unused for 90 days are already removed opportunistically whenever anyone creates a group. For a guaranteed sweep, add a weekly cron in the hosting panel:

    curl -s "https://pickle.digitelos.co.uk/cleanup.php?key=YOUR_KEY" > /dev/null

"Unused" means no organiser claimed control or saved games - spectator views don't count.

## Updating

Upload the changed files over the old ones. `APP_VERSION` in `app.html` shows at the bottom of the app - bump it with every release so you can verify which build a phone is running (browser caches, especially iOS Safari, are the number one source of phantom bugs; see DECISIONS.md section 4). If any page embeds the app in an iframe, bump the `?v=` on the iframe src too.

## Embedding a group in another website

    <iframe id="pickleFrame" src="https://pickle.digitelos.co.uk/g/YOUR-GROUP?view=1&v=1"
            style="width:100%;height:820px;border:0;display:block;" scrolling="no"></iframe>
    <script>
    window.addEventListener("message", function (e) {
      if (e.data && e.data.slaptonPickleballHeight) {
        document.getElementById("pickleFrame").style.height = e.data.slaptonPickleballHeight + "px";
      }
    });
    </script>

The app reports its height so the frame can auto-size; without the script the frame stays fixed-height and mobile scrolling suffers.
