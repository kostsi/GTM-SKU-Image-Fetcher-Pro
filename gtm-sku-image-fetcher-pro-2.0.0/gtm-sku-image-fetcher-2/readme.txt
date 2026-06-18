=== GTM SKU Image Fetcher Pro ===
Version: 2.0.0
Author: KOSTSI / GTMobiles.gr
Requires WooCommerce: yes

== Τι κάνει ==

Βρίσκει WooCommerce προϊόντα που ΔΕΝ έχουν:
- Featured image (χαρακτηριστική εικόνα), ή
- Gallery με τουλάχιστον 3 φωτογραφίες

Αναζητά εικόνες στο internet μέσω Google Custom Search API
χρησιμοποιώντας SKU + τίτλο, κατεβάζει τις καλύτερες και τις
ορίζει αυτόματα ως featured image ή gallery.

Τρέχει στο BACKGROUND (Action Scheduler ή WP-Cron fallback)
— μπορείς να κλείσεις τη σελίδα και να επιστρέψεις αργότερα.

== Βασικές Λειτουργίες ==

Dashboard:
  - Live KPI cards (total products, χωρίς featured, χωρίς gallery, κτλ)
  - Live progress bar με auto-refresh κάθε 5 δευτ.
  - Pause / Resume job
  - Queue έως 2000 προϊόντα με AJAX (χωρίς page reload)

Settings:
  - Google API Key + CX (Search Engine ID)
  - Query template ({sku}, {title})
  - Επιλογή τι να γεμίσει (featured / gallery / και τα δύο)
  - Gallery target (πόσες εικόνες να στοχεύει)
  - Ελάχιστο μέγεθος εικόνας
  - Allowed domains φίλτρο

Logs:
  - Πλήρες logging με φίλτρα (level, product_id, SKU)
  - Αυτόματη εκκαθάριση (retention days)

== Εγκατάσταση ==

1. Ανέβασε τον φάκελο στο /wp-content/plugins/
2. Ενεργοποίησε από Plugins → Installed Plugins
3. Πήγαινε στο menu "GTM Image Fetcher"
4. Ρύθμισε Google API Key + CX στις Ρυθμίσεις
5. Πάτα "Έναρξη" στο Dashboard

== Google Custom Search Setup ==

1. Πήγαινε στο https://programmablesearchengine.google.com/
2. Δημιούργησε νέο Search Engine
3. Search the entire web: ON
4. Image search: ON
5. Αντέγραψε το Search Engine ID (CX)
6. Ενεργοποίησε το Custom Search JSON API στο Google Cloud Console
7. Δημιούργησε API Key (χωρίς περιορισμούς HTTP referer για server calls)

== Changelog ==

2.0.0
- Gallery support: γεμίζει gallery με 3+ φωτογραφίες
- Live Dashboard με AJAX polling
- Pause / Resume background jobs
- Stats persistence (independent από logs)
- Google Custom Search ONLY (αφαιρέθηκε Bing)
- 2 pages αναζήτησης (max 20 candidates)
- Βελτιωμένο SQL για εύρεση προϊόντων
- Admin CSS redesign με KPI cards

1.3.5 (previous version)
- Featured image only
- Google PSE + Bing support
