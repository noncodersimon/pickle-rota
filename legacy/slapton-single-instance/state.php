<?php
/* ============================================================
   Slapton Pickleball Scheduler - shared state backend
   ------------------------------------------------------------
   Upload this file into the SAME folder as pickleball.html.
   Nothing else to set up - the data file is created automatically.

   >>> CHANGE THE PASSWORD BELOW before you go live. <<<
   ============================================================ */

const CONTROL_PASSWORD = 'slapton';                 // <-- EDIT THIS
const STATE_FILE = __DIR__ . '/pickleball-state.php'; // data file (self-protecting, see GUARD)

// The data file begins with this so that if anyone requests it directly
// in a browser, PHP just returns 403 instead of revealing the contents.
const GUARD = "<?php http_response_code(403); exit; ?>\n";

header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = $_REQUEST['action'] ?? 'read';
$token  = (string)($_REQUEST['token'] ?? '');

$fp = fopen(STATE_FILE, 'c+');
if (!$fp) { http_response_code(500); echo json_encode(['error' => 'store']); exit; }
flock($fp, LOCK_EX);

$raw = stream_get_contents($fp);
$json = $raw;
$pos = strpos($raw, '?>');                 // strip the PHP guard prefix if present
if ($pos !== false) $json = substr($raw, $pos + 2);
$store = json_decode(trim($json), true);
if (!is_array($store)) {
  $store = ['rev' => 0, 'controller' => null, 'since' => null, 'state' => null];
}

$dirty = false;
$response = null;

function view($store, $token) {
  $controlled = !empty($store['controller']);
  return [
    'rev'        => $store['rev'],
    'state'      => $store['state'],
    'controlled' => $controlled,
    'youControl' => $controlled && hash_equals((string)$store['controller'], (string)$token),
    'since'      => $store['since'],
  ];
}

if ($action === 'read') {
  $response = view($store, $token);

} elseif ($action === 'claim') {
  $pw = (string)($_POST['password'] ?? '');
  if (!hash_equals(CONTROL_PASSWORD, $pw)) {
    http_response_code(403);
    $response = ['error' => 'badpass'];
  } else {
    $store['controller'] = bin2hex(random_bytes(16)); // new token invalidates any previous controller
    $store['since']      = time();
    $store['rev']++;
    $dirty = true;
    $response = view($store, $store['controller']);
    $response['token'] = $store['controller'];         // only the new controller ever receives this
  }

} elseif ($action === 'save') {
  if (!empty($store['controller']) && hash_equals((string)$store['controller'], $token)) {
    $newState = json_decode((string)($_POST['state'] ?? ''), true);
    if (is_array($newState)) {
      $store['state'] = $newState;
      $store['rev']++;
      $dirty = true;
      $response = ['ok' => true, 'rev' => $store['rev']];
    } else {
      http_response_code(400);
      $response = ['error' => 'badstate'];
    }
  } else {
    http_response_code(409);
    $response = ['error' => 'lostcontrol'];
  }

} elseif ($action === 'release') {
  if (!empty($store['controller']) && hash_equals((string)$store['controller'], $token)) {
    $store['controller'] = null;
    $store['since']      = null;
    $store['rev']++;
    $dirty = true;
  }
  $response = view($store, '');

} else {
  http_response_code(400);
  $response = ['error' => 'badaction'];
}

if ($dirty) {
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, GUARD . json_encode($store));
  fflush($fp);
}
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode($response);
