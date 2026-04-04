/**
 * Bonjour Metal — app.js
 */

// ---- Effet poussière (grain statique) ----
(function initGrain() {
  const canvas = document.createElement('canvas');
  canvas.id = 'grain-canvas';
  document.body.appendChild(canvas);
  const ctx = canvas.getContext('2d');

  function draw() {
    const w = canvas.width  = window.innerWidth;
    const h = canvas.height = window.innerHeight;
    const buf = new Uint8ClampedArray(w * h * 4);
    for (let i = 0; i < buf.length; i += 4) {
      // ~3.5 % des pixels allumés, grain fixe
      const v = Math.random() < 0.035 ? (Math.random() * 180 + 60) | 0 : 0;
      buf[i] = buf[i + 1] = buf[i + 2] = v;
      buf[i + 3] = v ? (Math.random() * 22 + 6) | 0 : 0;
    }
    ctx.putImageData(new ImageData(buf, w, h), 0, 0);
  }

  draw();
  window.addEventListener('resize', draw);
}());

const VIDEOS_URL = "videos.json";
let pendingVideoId = null;
let player = null;
let playerReady = false;

// YouTube IFrame API — appelé automatiquement quand la lib est prête
window.onYouTubeIframeAPIReady = function () {
  player = new YT.Player("yt-player", {
    playerVars: { rel: 0, modestbranding: 1 },
    events: {
      onReady: () => { playerReady = true; },
    },
  });
};

function todayISO() {
  return new Date().toISOString().slice(0, 10);
}

function formatDate(iso) {
  const [year, month, day] = iso.split("-");
  const d = new Date(year, month - 1, day);
  return d.toLocaleDateString("en-CA", {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

async function loadVideo() {
  const today = todayISO();
  document.getElementById("nav-date").textContent = formatDate(today);

  let videos;
  try {
    const resp = await fetch(VIDEOS_URL);
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    videos = await resp.json();
  } catch (err) {
    console.error("Impossible de charger videos.json :", err);
    showNotFound();
    return;
  }

  const entry = videos.find((v) => v.date === today);
  if (!entry) { showNotFound(); return; }

  document.title = `${entry.artist} — ${entry.title} | Bonjour Metal`;

  const spotifyLink = document.getElementById("spotify-link");
  if (entry.spotify_url) {
    spotifyLink.href = entry.spotify_url;
    spotifyLink.classList.remove("hidden");
  } else {
    spotifyLink.classList.add("hidden");
  }

  buildTicker(entry.artist, entry.title);
  pendingVideoId = entry.youtube_id;

  document.getElementById("play-btn").addEventListener("click", () => {
    if (playerReady && pendingVideoId) {
      player.loadVideoById(pendingVideoId);
      player.playVideo();
    }
    document.getElementById("player-wrap").scrollIntoView({ behavior: "smooth", block: "nearest" });
  });
}

function buildTicker(artist, title) {
  const track = document.getElementById('ticker-track');
  if (!track) return;

  const unit =
    `<span class="ticker-unit">` +
      `<span class="ticker-artist">${artist}</span>` +
      `<span class="ticker-sep">//</span>` +
      `<span class="ticker-title">${title}</span>` +
      `<span class="ticker-sep-end">//</span>` +
    `</span>`;

  // 8 copies × 2 = 16 total ; on anime -50% → seamless loop
  const copies = Array(8).fill(unit).join('');
  track.innerHTML = copies + copies;

  // Vitesse dynamique : ~120 px/s
  requestAnimationFrame(() => {
    const halfWidth = track.scrollWidth / 2;
    const speed = 40; // px par seconde
    track.style.setProperty('--ticker-dur', `${(halfWidth / speed).toFixed(1)}s`);
  });
}

function showNotFound() {
  document.getElementById("hero-content").classList.add("hidden");
  document.getElementById("not-found").classList.remove("hidden");
}

loadVideo();

// Arrêter la vidéo quand le player quitte le viewport
const playerObserver = new IntersectionObserver(
  (entries) => {
    entries.forEach((e) => {
      if (!playerReady) return;
      if (!e.isIntersecting) player.stopVideo();
    });
  },
  { threshold: 0.1 }
);
playerObserver.observe(document.getElementById("player-wrap"));

// ---- Égaliseur audio ----
(function initEqualizer() {
  const container = document.querySelector('.cta-signal');
  if (!container) return;

  // Espace de coordonnées fixe — pas besoin de mesurer la largeur réelle.
  // Le SVG width="100%" + viewBox + preserveAspectRatio="none" s'adapte tout seul.
  const VW = 400, VH = 30, mid = VH / 2, step = 10;
  const NS = 'http://www.w3.org/2000/svg';

  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('width', '100%');
  svg.setAttribute('height', VH);
  svg.setAttribute('viewBox', `0 0 ${VW} ${VH}`);
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.style.display = 'block';
  container.appendChild(svg);

  const lines = [], phases = [], speeds = [], minAmps = [], maxAmps = [];
  const numBars = Math.floor(VW / step); // 40 barres fixes

  for (let i = 0; i < numBars; i++) {
    const x = i * step + 1;
    const ln = document.createElementNS(NS, 'line');
    ln.setAttribute('x1', x); ln.setAttribute('x2', x);
    ln.setAttribute('y1', mid); ln.setAttribute('y2', mid);
    ln.setAttribute('stroke', 'rgb(248, 230, 209)');
    ln.setAttribute('opacity', '0.9');
    ln.setAttribute('stroke-width', '1');
    ln.setAttribute('shape-rendering', 'geometricPrecision');
    ln.setAttribute('vector-effect', 'non-scaling-stroke'); // épaisseur constante même après scale
    svg.appendChild(ln);
    lines.push(ln);
    phases.push(Math.random() * Math.PI * 2);
    speeds.push(4 + Math.random() * 6);
    minAmps.push(1 + Math.random() * 2);
    maxAmps.push(4 + Math.random() * 9);
  }

  let t0 = null;
  function tick(ts) {
    if (!t0) t0 = ts;
    const t = (ts - t0) * 0.001;
    lines.forEach((ln, i) => {
      const v = (Math.sin(t * speeds[i] + phases[i])
               + Math.sin(t * speeds[i] * 0.7 + phases[i] * 1.3)) * 0.5;
      const amp = minAmps[i] + (maxAmps[i] - minAmps[i]) * (v * 0.5 + 0.5);
      ln.setAttribute('y1', (mid - amp).toFixed(2));
      ln.setAttribute('y2', (mid + amp).toFixed(2));
    });
    requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}());

// ---- Guitariste ----
(function initGuitarist() {
  const img = document.createElement('img');
  img.src = 'guitarman.png';
  img.alt = '';
  img.setAttribute('aria-hidden', 'true');
  img.style.imageRendering = 'pixelated';

  const wrapper = document.createElement('div');
  wrapper.className = 'guitarist';
  wrapper.appendChild(img);
  document.querySelector('.main').appendChild(wrapper);
}());

// ---- Batteur ----
(function initDrummer() {
  const img = document.createElement('img');
  img.src = 'drummer.png';
  img.alt = '';
  img.setAttribute('aria-hidden', 'true');
  img.style.imageRendering = 'pixelated';

  const wrapper = document.createElement('div');
  wrapper.className = 'drummer';
  wrapper.appendChild(img);
  document.querySelector('.main').appendChild(wrapper);
}());
