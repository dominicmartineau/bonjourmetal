# 🤘 Bonjour Metal

> One heavy music video. Every day.

A minimal, editorial dark-themed site that surfaces one metal music video per day.

**Live site → [dominicmartineau.github.io/bonjourmetal](https://dominicmartineau.github.io/bonjourmetal)**

---

## How it works

Each day, the site picks one video from `docs/videos.json` based on the current date and displays it using the YouTube IFrame API. The video schedule is pre-generated from Spotify playlist exports.

---

## Project structure

```
bonjourmetal/
├── data/               # Spotify playlist exports (CSV via exportify.net)
│   └── playlist*.csv
├── docs/               # Static site served via GitHub Pages
│   ├── index.html
│   ├── style.css
│   ├── app.js
│   ├── videos.json     # Pre-generated schedule (one video per day)
│   ├── guitarman.png
│   └── drummer.png
└── scripts/
    └── generate.php    # Generates videos.json from CSV + yt-dlp
```

---

## Regenerating the video schedule

### Requirements

- PHP 8+
- [yt-dlp](https://github.com/yt-dlp/yt-dlp) installed and in your `$PATH`
- Composer dependencies installed

```bash
composer install
```

### Steps

1. Export your Spotify playlists via [exportify.net](https://exportify.net) and place the CSV files in `data/`
2. Run the generator:

```bash
php scripts/generate.php
```

This will update `docs/videos.json` with one YouTube video per day.

---

## Deployment

The site is deployed via **GitHub Pages** from the `docs/` folder on the `master` branch.

**Settings → Pages → Branch: `master` / Folder: `/docs`**

Any push to `master` automatically updates the live site.

---

## Design

- **Font**: Anton (display) + Poppins (body)
- **Background**: `#120d14` (dark plum) with a 44px grid pattern
- **Accent colors**: Gold `#fcc716` · Cyan `rgb(0, 160, 204)` · Cream `rgb(248, 230, 209)`
- Grain/dust overlay, large scrolling ticker, SVG audio equalizer animation
