<?php
require_once __DIR__ . '/config.php';

/**
 * Connect to DB
 */
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    http_response_code(500);
    die("Database connection failed: " . htmlspecialchars(mysqli_connect_error()));
}
mysqli_set_charset($conn, 'utf8mb4');

/**
 * Helpers
 */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fetch_player(mysqli $conn): array {
    $res = mysqli_query($conn, "SELECT * FROM player ORDER BY id ASC LIMIT 1");
    if (!$res) {
        return ['id' => 1, 'location' => 'start', 'health' => 100];
    }
    $row = mysqli_fetch_assoc($res);
    if (!$row) {
        // If somehow missing, create it
        mysqli_query($conn, "INSERT INTO player (location, health) VALUES ('start', 100)");
        return fetch_player($conn);
    }
    return $row;
}

function set_player_location(mysqli $conn, string $loc): void {
    $stmt = mysqli_prepare($conn, "UPDATE player SET location=? WHERE id=(SELECT id FROM (SELECT id FROM player ORDER BY id ASC LIMIT 1) p)");
    mysqli_stmt_bind_param($stmt, "s", $loc);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function add_item(mysqli $conn, string $item): bool {
    $stmt = mysqli_prepare($conn, "INSERT INTO inventory (item_name) VALUES (?)");
    mysqli_stmt_bind_param($stmt, "s", $item);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function remove_item(mysqli $conn, string $item): bool {
    $stmt = mysqli_prepare($conn, "DELETE FROM inventory WHERE item_name=?");
    mysqli_stmt_bind_param($stmt, "s", $item);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function has_item(mysqli $conn, string $item): bool {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM inventory WHERE item_name=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $item);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

function list_inventory(mysqli $conn): array {
    $items = [];
    $res = mysqli_query($conn, "SELECT item_name FROM inventory ORDER BY item_name ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $items[] = $row['item_name'];
        }
    }
    return $items;
}

function log_command(mysqli $conn, string $cmd): void {
    $stmt = mysqli_prepare($conn, "INSERT INTO command_log (command_text) VALUES (?)");
    mysqli_stmt_bind_param($stmt, "s", $cmd);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function get_recent_log(mysqli $conn, int $limit = 8): array {
    $rows = [];
    $stmt = mysqli_prepare($conn, "SELECT command_text, created_at FROM command_log ORDER BY id DESC LIMIT ?");
    mysqli_stmt_bind_param($stmt, "i", $limit);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * World definition (simple map)
 */
$rooms = [
    'start' => [
        'title' => 'Start Room',
        'desc'  => 'You wake up in a small room. There is a door to the NORTH.',
        'exits' => ['north' => 'hallway'],
        'items' => ['key'], // available only if not already in inventory
    ],
    'hallway' => [
        'title' => 'Hallway',
        'desc'  => 'A long hallway. Doors lead SOUTH and EAST. A heavy door to the NORTH.',
        'exits' => ['south' => 'start', 'east' => 'armory', 'north' => 'treasure_room'],
        'items' => [],
    ],
    'armory' => [
        'title' => 'Armory',
        'desc'  => 'Old shelves. Something shiny catches your eye.',
        'exits' => ['west' => 'hallway'],
        'items' => ['coin'],
    ],
    'treasure_room' => [
        'title' => 'Treasure Room',
        'desc'  => 'The treasure room glows. You made it.',
        'exits' => ['south' => 'hallway'],
        'items' => [],
    ],
];

/**
 * Game logic
 */
$player = fetch_player($conn);
$location = $player['location'];
$message = "";
$didWin = false;

$rawCommand = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawCommand = (string)($_POST['command'] ?? '');
    $command = strtolower(trim($rawCommand));

    // log raw normalized command for history
    if ($command !== '') {
        log_command($conn, $command);
    }

    // Commands
    if ($command === 'help') {
        $message = "Try: look, go north/south/east/west, take key/coin, drop key/coin, inventory, reset";
    } elseif ($command === 'look') {
        $message = $rooms[$location]['desc'] ?? "You see nothing interesting.";
    } elseif (preg_match('/^go\s+(north|south|east|west)$/', $command, $m)) {
        $dir = $m[1];
        $exits = $rooms[$location]['exits'] ?? [];
        if (!isset($exits[$dir])) {
            $message = "You can't go $dir from here.";
        } else {
            $next = $exits[$dir];

            // Gate: require key to enter treasure_room
            if ($next === 'treasure_room' && !has_item($conn, 'key')) {
                $message = "The door is locked. You need a key.";
            } else {
                set_player_location($conn, $next);
                $location = $next;
                $message = "You go $dir.";

                if ($location === 'treasure_room') {
                    $didWin = true;
                    $message .= " The treasure is yours. You win! 🎉";
                }
            }
        }
    } elseif (preg_match('/^take\s+([a-z0-9_ -]+)$/', $command, $m)) {
        $item = trim($m[1]);
        $roomItems = $rooms[$location]['items'] ?? [];

        // Allow only items that exist in this room, and not already owned
        if (!in_array($item, $roomItems, true)) {
            $message = "You don't see '$item' here.";
        } elseif (has_item($conn, $item)) {
            $message = "You already have '$item'.";
        } else {
            $ok = add_item($conn, $item);
            $message = $ok ? "You pick up the $item." : "Couldn't take '$item' (maybe it already exists).";
        }
    } elseif (preg_match('/^drop\s+([a-z0-9_ -]+)$/', $command, $m)) {
        $item = trim($m[1]);
        if (!has_item($conn, $item)) {
            $message = "You don't have '$item'.";
        } else {
            $ok = remove_item($conn, $item);
            $message = $ok ? "You drop the $item." : "Couldn't drop '$item'.";
        }
    } elseif ($command === 'inventory') {
        $inv = list_inventory($conn);
        $message = empty($inv) ? "Your inventory is empty." : "Inventory: " . implode(", ", $inv);
    } elseif ($command === 'reset') {
        mysqli_query($conn, "DELETE FROM inventory");
        mysqli_query($conn, "DELETE FROM command_log");
        set_player_location($conn, 'start');
        $location = 'start';
        $message = "Game reset. Back to the start room.";
    } elseif ($command === '') {
        $message = "Type a command (try 'help').";
    } else {
        $message = "Unknown command. Try 'help'.";
    }

    // Refresh player after actions
    $player = fetch_player($conn);
    $location = $player['location'];
}

// Current room info
$roomTitle = $rooms[$location]['title'] ?? "Unknown";
$roomDesc  = $rooms[$location]['desc'] ?? "This place doesn't exist.";
$inv = list_inventory($conn);
$log = get_recent_log($conn, 8);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>PHP Text Adventure</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: Arial, sans-serif; max-width: 900px; margin: 32px auto; padding: 0 16px; }
    .card { border: 1px solid #ddd; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .muted { color: #666; }
    input[type="text"] { width: 100%; padding: 12px; font-size: 16px; border-radius: 10px; border: 1px solid #ccc; }
    button { padding: 10px 14px; font-size: 16px; border-radius: 10px; border: 1px solid #ccc; cursor: pointer; }
    .row { display: flex; gap: 12px; align-items: center; }
    .row > * { flex: 1; }
    .pill { display:inline-block; padding: 4px 10px; border: 1px solid #ddd; border-radius: 999px; margin-right: 6px; }
    .log { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 14px; }
  </style>
</head>
<body>

  <h1>PHP Text Adventure</h1>
  <p class="muted">Commands: <span class="pill">help</span><span class="pill">look</span><span class="pill">go north/south/east/west</span><span class="pill">take key/coin</span><span class="pill">drop key/coin</span><span class="pill">inventory</span><span class="pill">reset</span></p>

  <div class="card">
    <h2><?php echo h($roomTitle); ?></h2>
    <p><?php echo h($roomDesc); ?></p>

    <?php if ($message !== ""): ?>
      <p><strong><?php echo h($message); ?></strong></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <form method="POST">
      <label for="command"><strong>Type a command:</strong></label><br><br>
      <div class="row">
        <input id="command" name="command" type="text" autocomplete="off" autofocus value="<?php echo h($rawCommand); ?>" />
        <button type="submit">Submit</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Inventory</h3>
    <?php if (empty($inv)): ?>
      <p class="muted">Empty</p>
    <?php else: ?>
      <p><?php echo h(implode(", ", $inv)); ?></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Recent Commands</h3>
    <?php if (empty($log)): ?>
      <p class="muted">No commands yet.</p>
    <?php else: ?>
      <ul class="log">
        <?php foreach ($log as $row): ?>
          <li><?php echo h($row['created_at']); ?> — <?php echo h($row['command_text']); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

</body>
</html>