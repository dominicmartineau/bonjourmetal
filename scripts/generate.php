<?php
declare(strict_types=1);

/**
 * Bonjour Métal — Générateur de la banque de vidéos
 * Lit des fichiers CSV exportés via exportify.net,
 * re-cherche la meilleure vidéo YouTube de chaque chanson via yt-dlp,
 * et génère docs/videos.json (le site en affiche une au hasard).
 *
 * Usage : php scripts/generate.php
 */

// ============================================================
// Config
// ============================================================

$csvDir     = __DIR__ . '/../data';
$outputFile = __DIR__ . '/../docs/videos.json';

// ============================================================
// Lecture des CSV (exportify.net)
// ============================================================

function fetchAllTracks(string $csvDir): array
{
    $seen      = [];
    $allTracks = [];
    $files     = glob("{$csvDir}/*.csv");

    if (empty($files)) {
        echo "  ⚠️  Aucun fichier CSV trouvé dans data/\n";
        return [];
    }

    foreach ($files as $file) {
        $filename = basename($file);
        echo "  📋 {$filename}...\n";
        $count = 0;

        $handle = fopen($file, 'r');
        // Retirer le BOM UTF-8 éventuel et lire l'en-tête
        $header = fgetcsv($handle, 0, ',', '"', '');
        $header[0] = ltrim($header[0], "\xEF\xBB\xBF");

        // Trouver les index des colonnes qui nous intéressent
        $titleIdx  = array_search('Track Name',     $header);
        $artistIdx = array_search('Artist Name(s)', $header);
        $uriIdx    = array_search('Track URI',      $header);

        if ($titleIdx === false || $artistIdx === false) {
            echo "  ⚠️  Colonnes introuvables dans {$filename}, on passe.\n";
            fclose($handle);
            continue;
        }

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $title  = trim($row[$titleIdx]  ?? '');
            $artist = trim($row[$artistIdx] ?? '');

            if ($title === '' || $artist === '') continue;

            // Exportify sépare plusieurs artistes par « ; » — on prend le premier
            $artist = trim(explode(';', $artist)[0]);

            // Extraire l'URL Spotify depuis l'URI (ex: spotify:track:abc123)
            $spotifyUri = $uriIdx !== false ? trim($row[$uriIdx] ?? '') : '';
            $spotifyId  = explode(':', $spotifyUri)[2] ?? '';
            $spotifyUrl = $spotifyId ? "https://open.spotify.com/track/{$spotifyId}" : '';

            $key = strtolower($artist) . '|' . strtolower($title);
            if (!isset($seen[$key])) {
                $seen[$key]  = true;
                $allTracks[] = ['artist' => $artist, 'title' => $title, 'spotify_url' => $spotifyUrl];
                $count++;
            }
        }

        fclose($handle);
        echo "      → {$count} chansons\n";
    }

    echo '  ✅ ' . count($allTracks) . " chansons uniques trouvées\n";
    return $allTracks;
}

// ============================================================
// YouTube (via yt-dlp CLI — aucune clé API requise)
// ============================================================

function normalize(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim($s);
}

// Retirer « (feat. …) », « - Remastered », etc. du titre de la chanson
function songCore(string $song): string
{
    return normalize(preg_replace('/\s+-\s.*$|\s*[(\[].*$/', '', $song));
}

function matchesTrack(array $video, string $artist, string $song): bool
{
    $title   = normalize($video['title'] ?? '');
    $channel = str_replace(' ', '', normalize($video['channel'] ?? $video['uploader'] ?? ''));
    $artistN = normalize($artist);

    $hasSong   = str_contains(" {$title} ", ' ' . songCore($song) . ' ');
    $hasArtist = str_contains($title, $artistN) || str_contains($channel, str_replace(' ', '', $artistN));

    return $hasSong && $hasArtist;
}

function scoreVideo(array $video, string $artist, string $song): int
{
    $score    = 0;
    $title    = normalize($video['title'] ?? '');
    $channelN = normalize($video['channel'] ?? $video['uploader'] ?? '');
    $channel  = str_replace(' ', '', $channelN);
    $artistN  = normalize($artist);
    $songN    = songCore($song);

    // La vidéo doit correspondre à la chanson ET à l'artiste
    if (matchesTrack($video, $artist, $song)) $score += 12;
    if (str_contains($title, $artistN))       $score += 4;
    else if (!str_contains($channel, str_replace(' ', '', $artistN))) $score -= 10;

    $official = false;
    foreach (['official video', 'official music video', 'official clip', 'official mv'] as $kw) {
        if (str_contains($title, $kw)) { $score += 10; $official = true; break; }
    }
    if (!$official) {
        foreach (['official visualizer', 'official visualiser', 'official lyric video', 'official audio', 'visualizer', 'visualiser'] as $kw) {
            if (str_contains($title, $kw)) { $score += 5; break; }
        }
    }

    if (str_contains($channel, 'vevo'))                            $score += 8;
    if (str_contains($channel, str_replace(' ', '', $artistN)))    $score += 6;
    if (str_contains($channelN, 'topic')) {
        $score += 3;
        if ($title === $songN) $score += 3; // piste audio officielle
    }

    if (str_contains($title, 'lyric')) $score -= 2;
    if (str_contains(" {$title} ", ' live '))  $score -= 8;
    if (str_contains(" {$title} ", ' cover ')) $score -= 5;

    // Contenu qui n'est pas la chanson elle-même
    foreach (['reaction', 'react', 'fan video', 'fan made', 'fanmade', 'full album', 'album stream',
              'track by track', 'recording', 'playthrough', 'drum cam', 'tutorial', 'karaoke',
              'joue', 'plays', 'interview', 'behind the scenes', 'making of', 'unofficial',
              'tradução', 'traducao', 'traduction', 'legendado', 'subtitulado', 'nightcore', 'slowed'] as $kw) {
        if (str_contains(" {$title} ", " {$kw} ")) { $score -= 20; break; }
    }

    return $score;
}

function searchYouTube(string $query, int $count): array
{
    $escaped = escapeshellarg("ytsearch{$count}:{$query}");
    $cmd     = "/usr/local/bin/yt-dlp --flat-playlist --dump-json --no-warnings --quiet {$escaped} 2>/dev/null";

    exec($cmd, $lines);

    $videos = [];
    foreach ($lines as $line) {
        $data = json_decode($line, true);
        if ($data) $videos[] = $data;
    }

    // Filtrer les vidéos non-embeddables (privées, premium, restreintes)
    return array_values(array_filter($videos, function ($v) {
        $availability = $v['availability'] ?? 'public';
        if (!in_array($availability, ['public', 'unlisted', null, ''], true)) return false;
        if (isset($v['is_embeddable']) && $v['is_embeddable'] === false) return false;
        return true;
    }));
}

function findYouTubeVideo(string $artist, string $title): ?array
{
    $videos = searchYouTube("{$artist} {$title} official video", 8);

    // Aucun résultat ne correspond à la chanson : deuxième recherche sans « official video »
    $hasMatch = fn($list) => !empty(array_filter($list, fn($v) => matchesTrack($v, $artist, $title)));
    if (!$hasMatch($videos)) {
        $videos = array_merge($videos, searchYouTube("{$artist} {$title}", 8));
    }

    if (empty($videos)) return null;

    usort($videos, fn($a, $b) => scoreVideo($b, $artist, $title) <=> scoreVideo($a, $artist, $title));
    $best = $videos[0];

    return [
        'match'           => matchesTrack($best, $artist, $title),
        'youtube_id'      => $best['id'],
        'youtube_title'   => $best['title']   ?? '',
        'youtube_channel' => $best['channel'] ?? $best['uploader'] ?? '',
    ];
}


// ============================================================
// Main
// ============================================================

echo "🤘 Bonjour Métal — Génération de la banque de vidéos\n\n";

echo "📂 Lecture des fichiers CSV...\n";
$tracks = fetchAllTracks($csvDir);

// Index des vidéos actuelles, pour garder l'ancienne si la recherche échoue
$existing = [];
if (file_exists($outputFile)) {
    foreach (json_decode(file_get_contents($outputFile), true) ?? [] as $e) {
        $existing[strtolower($e['artist']) . '|' . strtolower($e['title'])] = $e;
    }
}
echo '  📂 ' . count($existing) . " entrées existantes dans videos.json\n";

echo "\n🎬 Recherche des vidéos YouTube (via yt-dlp)...\n";
$result  = [];
$changed = 0;
$total   = count($tracks);

foreach ($tracks as $i => $track) {
    $artist = $track['artist'];
    $title  = $track['title'];
    $key    = strtolower($artist) . '|' . strtolower($title);
    $old    = $existing[$key] ?? null;
    $num    = $i + 1;

    echo "  🔍 [{$num}/{$total}] {$artist} — {$title}\n";

    $yt = findYouTubeVideo($artist, $title);

    if (!$yt) {
        if ($old) {
            echo "    ⚠️  Aucune vidéo trouvée, on garde l'ancienne.\n";
            $result[] = $old;
        } else {
            echo "    ❌ Aucune vidéo trouvée, on passe.\n";
        }
        continue;
    }

    // Aucune vidéo ne correspond à la chanson : on garde l'ancienne plutôt qu'une autre chanson
    $match = $yt['match'];
    unset($yt['match']);
    if (!$match && $old) {
        echo "    ⚠️  Aucune vidéo ne correspond exactement, on garde l'ancienne : {$old['youtube_title']}\n";
        $result[] = $old;
        continue;
    }
    if (!$match) echo "    ⚠️  Aucune vidéo ne correspond exactement, meilleur candidat retenu.\n";

    $result[] = array_merge(['artist' => $artist, 'title' => $title, 'spotify_url' => $track['spotify_url'] ?? ''], $yt);

    if ($old && $old['youtube_id'] !== $yt['youtube_id']) {
        $changed++;
        echo "    🔄 {$old['youtube_title']} → {$yt['youtube_title']} ({$yt['youtube_channel']})\n";
    } else {
        echo "    ✅ {$yt['youtube_title']} ({$yt['youtube_channel']})\n";
    }
    usleep(300_000); // 300ms entre les requêtes
}

file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "\n✅ " . count($result) . " vidéos enregistrées dans docs/videos.json\n";
echo "   Vidéos remplacées : {$changed}\n";
