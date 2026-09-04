<?php
/* ============================================================
   Pickle Rota - multi-tenant backend
   ------------------------------------------------------------
   One group = one JSON file in DATA_DIR, named {slug}.json.
   Passwords are hashed (password_hash). Control uses a rotating
   token: entering the password issues a new token and invalidates
   the previous holder. See docs/DECISIONS.md for the reasoning.
   ============================================================ */

const DATA_DIR   = __DIR__ . '/../data';  // keep OUTSIDE the webroot (see docs/DEPLOYMENT.md)
const MAX_GROUPS = 500;                   // hard cap on total groups (disk safety)
const STALE_DAYS = 90;                    // groups unused this long are removed
const MIN_PASS   = 4;
const MAX_STATE  = 200000;                // bytes; ~a very long session, generously

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond($code, $data){ http_response_code($code); echo json_encode($data); exit; }

function slug_ok($s){ return is_string($s) && preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $s); }
function gpath($slug){ return DATA_DIR . '/' . $slug . '.json'; }

/* Remove groups not used for STALE_DAYS. Runs on every create (opportunistic GC)
   and from cleanup.php (scheduled). lastUsed is only touched by claim/save, so
   "used" means someone actually ran games, not merely viewed. */
function gc(){
  $cut = time() - STALE_DAYS * 86400;
  foreach (glob(DATA_DIR . '/*.json') ?: [] as $f) {
    $d = json_decode((string)@file_get_contents($f), true);
    $last = is_array($d) ? (int)($d['lastUsed'] ?? 0) : 0;
    if ($last < $cut) @unlink($f);
  }
}

function view($store, $token){
  $controlled = !empty($store['controller']);
  return [
    'rev'        => $store['rev'],
    'state'      => $store['state'],
    'name'       => $store['name'],
    'controlled' => $controlled,
    'youControl' => $controlled && hash_equals((string)$store['controller'], (string)$token),
    'since'      => $store['since'],
  ];
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'read');

/* ---------- create a new group ---------- */
if ($action === 'create') {
  if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0755, true)) respond(500, ['error' => 'store']);
  gc();
  $name = trim((string)($_POST['name'] ?? ''));
  $pass = (string)($_POST['password'] ?? '');
  if ($name === '' || mb_strlen($name) > 40) respond(400, ['error' => 'badname']);
  if (strlen($pass) < MIN_PASS)              respond(400, ['error' => 'shortpass']);
  if (count(glob(DATA_DIR . '/*.json') ?: []) >= MAX_GROUPS) respond(503, ['error' => 'full']);

  $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
  if ($base === '') $base = 'group';
  $base = substr($base, 0, 30);
  $slug = $base;
  for ($i = 2; file_exists(gpath($slug)); $i++) {
    if ($i > 200) respond(500, ['error' => 'slug']);
    $slug = $base . '-' . $i;
  }

  $store = [
    'name' => $name,
    'pass' => password_hash($pass, PASSWORD_DEFAULT),
    'rev' => 0, 'controller' => null, 'since' => null,
    'created' => time(), 'lastUsed' => time(),
    'state' => null,
  ];
  if (file_put_contents(gpath($slug), json_encode($store), LOCK_EX) === false) {
    respond(500, ['error' => 'store']);   // data/ not writable - see docs/DEPLOYMENT.md step 3
  }
  respond(200, ['ok' => true, 'slug' => $slug, 'name' => $name]);
}

/* ---------- everything else operates on one group ---------- */
$slug = (string)($_POST['g'] ?? ($_GET['g'] ?? ''));
if (!slug_ok($slug))          respond(400, ['error' => 'badgroup']);
if (!file_exists(gpath($slug))) respond(404, ['error' => 'nogroup']);

$token = (string)($_POST['token'] ?? '');

$fp = fopen(gpath($slug), 'c+');
if (!$fp) respond(500, ['error' => 'store']);
flock($fp, LOCK_EX);
$store = json_decode((string)stream_get_contents($fp), true);
if (!is_array($store)) { flock($fp, LOCK_UN); fclose($fp); respond(500, ['error' => 'corrupt']); }

$dirty = false; $status = 200; $response = null;

if ($action === 'read') {
  $response = view($store, $token);

} elseif ($action === 'claim') {
  $pass = (string)($_POST['password'] ?? '');
  if (!password_verify($pass, $store['pass'])) {
    usleep(300000);            // mild brake on password guessing
    $status = 403; $response = ['error' => 'badpass'];
  } else {
    $store['controller'] = bin2hex(random_bytes(16));  // rotating token invalidates any previous controller
    $store['since'] = time();
    $store['lastUsed'] = time();
    $store['rev']++;
    $dirty = true;
    $response = view($store, $store['controller']);
    $response['token'] = $store['controller'];         // only the new controller ever receives this
  }

} elseif ($action === 'save') {
  if (!empty($store['controller']) && hash_equals((string)$store['controller'], $token)) {
    $rawState = (string)($_POST['state'] ?? '');
    if (strlen($rawState) > MAX_STATE) { $status = 413; $response = ['error' => 'toobig']; }
    else {
      $newState = json_decode($rawState, true);
      if (is_array($newState)) {
        $store['state'] = $newState;
        $store['lastUsed'] = time();
        $store['rev']++;
        $dirty = true;
        $response = ['ok' => true, 'rev' => $store['rev']];
      } else { $status = 400; $response = ['error' => 'badstate']; }
    }
  } else { $status = 409; $response = ['error' => 'lostcontrol']; }

} elseif ($action === 'release') {
  if (!empty($store['controller']) && hash_equals((string)$store['controller'], $token)) {
    $store['controller'] = null; $store['since'] = null; $store['rev']++; $dirty = true;
  }
  $response = view($store, '');

} else { $status = 400; $response = ['error' => 'badaction']; }

if ($dirty) {
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($store)); fflush($fp);
}
flock($fp, LOCK_UN); fclose($fp);
respond($status, $response);
