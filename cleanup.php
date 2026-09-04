<?php
/* Scheduled cleanup endpoint. Point a weekly cron at:
     https://pickle.digitelos.co.uk/cleanup.php?key=YOUR_KEY
   (GC also runs opportunistically on every group creation, so this is belt and braces.) */
const CLEANUP_KEY = 'change-me-before-deploy';
const DATA_DIR = __DIR__ . '/../data';
const STALE_DAYS = 90;
header('Content-Type: application/json');
if (!hash_equals(CLEANUP_KEY, (string)($_GET['key'] ?? ''))) { http_response_code(403); echo '{"error":"forbidden"}'; exit; }
$cut = time() - STALE_DAYS * 86400; $removed = 0; $kept = 0;
foreach (glob(DATA_DIR . '/*.json') as $f) {
  $d = json_decode((string)@file_get_contents($f), true);
  $last = is_array($d) ? (int)($d['lastUsed'] ?? 0) : 0;
  if ($last < $cut) { @unlink($f); $removed++; } else { $kept++; }
}
echo json_encode(['ok' => true, 'removed' => $removed, 'kept' => $kept]);
