<?php
declare(strict_types=1);

/**
 * Bonjour Métal — Générateur de playlist quotidienne
 * Lit des fichiers CSV exportés via exportify.net,
 * trouve les vidéos YouTube via yt-dlp,
 * et génère docs/videos.json avec une vidéo par jour.
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

            // Exportify peut lister plusieurs artistes séparés par une virgule — on prend le premier
            $artist = explode(',', $artist)[0];

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

function scoreVideo(array $video, string $artist): int
{
    $score   = 0;
    $title   = strtolower($video['title']   ?? '');
    $channel = strtolower($video['channel'] ?? $video['uploader'] ?? '');
    $artistL = strtolower($artist);

    foreach (['official video', 'official music video', 'official clip', 'official mv'] as $kw) {
        if (str_contains($title, $kw)) { $score += 10; break; }
    }

    if (str_contains($channel, 'vevo'))   $score += 8;
    if (str_contains($channel, $artistL)) $score += 5;
    if (str_contains($channel, 'topic'))  $score += 3;
    if (str_contains($title,   'lyric'))  $score -= 2;
    if (str_contains($title,   'cover'))  $score -= 5;
    if (str_contains($title,   'live'))   $score -= 3;

    return $score;
}

function findYouTubeVideo(string $artist, string $title): ?array
{
    $query   = "{$artist} {$title} official video";
    $escaped = escapeshellarg("ytsearch5:{$query}");
    $cmd     = "/usr/local/bin/yt-dlp --flat-playlist --dump-json --no-warnings --quiet {$escaped} 2>/dev/null";

    exec($cmd, $lines);

    $videos = [];
    foreach ($lines as $line) {
        $data = json_decode($line, true);
        if ($data) $videos[] = $data;
    }

    if (empty($videos)) return null;

    // Filtrer les vidéos non-embeddables (privées, premium, restreintes)
    $videos = array_filter($videos, function ($v) {
        $availability = $v['availability'] ?? 'public';
        if (!in_array($availability, ['public', 'unlisted', null, ''], true)) return false;
        if (isset($v['is_embeddable']) && $v['is_embeddable'] === false) return false;
        return true;
    });

    if (empty($videos)) return null;

    usort($videos, fn($a, $b) => scoreVideo($b, $artist) <=> scoreVideo($a, $artist));
    $best = array_values($videos)[0];

    return [
        'youtube_id'      => $best['id'],
        'youtube_title'   => $best['title']   ?? '',
        'youtube_channel' => $best['channel'] ?? $best['uploader'] ?? '',
    ];
}


// ============================================================
// JSON daté
// ============================================================

function loadExisting(string $path): array
{
    if (!file_exists($path)) return [];
    return json_decode(file_get_contents($path), true) ?? [];
}

function nextAvailableDate(array $existing): DateTimeImmutable
{
    if (empty($existing)) return new DateTimeImmutable('today');

    $dates = array_map(fn($e) => new DateTimeImmutable($e['date']), $existing);
    $last  = max($dates);
    return $last->modify('+1 day');
}

function noConsecutiveArtists(array $tracks, ?string $lastArtist): array
{
    $remaining  = array_values($tracks);
    $ordered    = [];
    $prevArtist = strtolower($lastArtist ?? '');

    while (!empty($remaining)) {
        $idx = 0; // fallback : on prend le premier si tous sont le même artiste
        foreach ($remaining as $i => $track) {
            if (strtolower($track['artist']) !== $prevArtist) {
                $idx = $i;
                break;
            }
        }
        $ordered[]  = $remaining[$idx];
        $prevArtist = strtolower($remaining[$idx]['artist']);
        array_splice($remaining, $idx, 1);
    }

    return $ordered;
}

function buildDatedList(array $tracks, array $existing): array
{
    // Construire un index des chansons déjà planifiées
    $existingKeys = [];
    foreach ($existing as $e) {
        $existingKeys[strtolower($e['artist']) . '|' . strtolower($e['title'])] = true;
    }

    // Garder seulement les nouvelles chansons
    $newTracks = array_values(array_filter($tracks, function ($t) use ($existingKeys) {
        return !isset($existingKeys[strtolower($t['artist']) . '|' . strtolower($t['title'])]);
    }));

    shuffle($newTracks);

    if (empty($newTracks)) {
        echo "  ℹ️  Aucune nouvelle chanson à ajouter.\n";
        return $existing;
    }

    // Réordonner pour éviter le même artiste 2 jours de suite
    $lastArtist = !empty($existing) ? end($existing)['artist'] : null;
    $newTracks  = noConsecutiveArtists($newTracks, $lastArtist);

    $startDate = nextAvailableDate($existing);
    $result    = $existing;
    $total     = count($newTracks);
    $newAdded  = 0;

    foreach ($newTracks as $i => $track) {
        $artist = $track['artist'];
        $title  = $track['title'];
        $num    = $i + 1;

        echo "  🔍 [{$num}/{$total}] {$artist} — {$title}\n";

        $yt = findYouTubeVideo($artist, $title);

        if (!$yt) {
            echo "    ❌ Aucune vidéo trouvée, on passe.\n";
            continue;
        }

        $date     = $startDate->modify("+{$newAdded} days")->format('Y-m-d');
        $result[] = array_merge(['date' => $date, 'artist' => $artist, 'title' => $title, 'spotify_url' => $track['spotify_url'] ?? ''], $yt);
        $newAdded++;

        echo "    ✅ {$yt['youtube_title']} ({$yt['youtube_channel']})\n";
        usleep(300_000); // 300ms entre les requêtes
    }

    return $result;
}

// ============================================================
// Main
// ============================================================

echo "🤘 Bonjour Métal — Génération de la liste de vidéos\n\n";

echo "📂 Lecture des fichiers CSV...\n";
$tracks = fetchAllTracks($csvDir);
shuffle($tracks);

$existing = loadExisting($outputFile);
echo '  📂 ' . count($existing) . " entrées existantes dans videos.json\n";

echo "\n🎬 Recherche des vidéos YouTube (via yt-dlp)...\n";
$result = buildDatedList($tracks, $existing);

file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$newCount  = count($result) - count($existing);
$totalDays = count($result);
$years     = round($totalDays / 365, 1);

echo "\n✅ {$totalDays} vidéos enregistrées dans docs/videos.json\n";
echo "   Nouvelles : {$newCount}\n";
echo "   Couverture : {$totalDays} jours ({$years} ans)\n";
