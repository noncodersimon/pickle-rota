<?php
/* Per-group web app manifest so Add to Home Screen opens the right group */
header('Content-Type: application/manifest+json');
$g = (string)($_GET['g'] ?? '');
$name = 'Pickle Rota';
if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $g)) {
  $d = json_decode((string)@file_get_contents(__DIR__ . '/../data/' . $g . '.json'), true);
  if (is_array($d) && !empty($d['name'])) $name = $d['name'];
  $start = '/g/' . $g;
} else { $start = '/'; }
echo json_encode([
  'name' => $name, 'short_name' => 'Pickle Rota',
  'start_url' => $start, 'scope' => '/', 'display' => 'standalone', 'orientation' => 'portrait',
  'background_color' => '#eef2f1', 'theme_color' => '#0f766e',
  'icons' => [
    ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
  ],
]);
