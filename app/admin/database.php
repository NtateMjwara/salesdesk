<?php
/**
 * db_manager.php — Lightweight database manager.
 * Place in the same directory as config.php (or adjust the require path).
 * IMPORTANT: Protect this file in production (IP restriction, .htaccess, or delete after use).
 */

session_start();
require_once '../../includes/config.php';

// ─── ACCESS CONTROL ──────────────────────────────────────────────────────────


// ─── DATABASE CONNECTION ──────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ─── ROUTING ──────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'tables';
$table  = isset($_GET['table'])  ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table'])  : '';
$msg    = '';
$error  = '';

try {
    $pdo = getDB();

    // ── AJAX: inline cell save ──────────────────────────────────────────────
    if ($action === 'ajax_save_cell' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $t    = preg_replace('/[^a-zA-Z0-9_]/', '', $data['table']);
        $col  = preg_replace('/[^a-zA-Z0-9_]/', '', $data['col']);
        $pkCol= preg_replace('/[^a-zA-Z0-9_]/', '', $data['pk_col']);
        $val  = $data['value'];
        $pk   = $data['pk_val'];
        $stmt = $pdo->prepare("UPDATE `$t` SET `$col` = ? WHERE `$pkCol` = ?");
        $stmt->execute([$val, $pk]);
        echo json_encode(['ok' => true, 'affected' => $stmt->rowCount()]);
        exit;
    }

    // ── DELETE ROW ──────────────────────────────────────────────────────────
    if ($action === 'delete_row' && $table && isset($_GET['pk_col'], $_GET['pk_val'])) {
        $pkCol = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['pk_col']);
        $stmt  = $pdo->prepare("DELETE FROM `$table` WHERE `$pkCol` = ?");
        $stmt->execute([$_GET['pk_val']]);
        $msg = "Row deleted successfully.";
        $action = 'browse';
    }

    // ── INSERT ROW ──────────────────────────────────────────────────────────
    if ($action === 'do_insert' && $table && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cols = array_map(fn($c) => preg_replace('/[^a-zA-Z0-9_]/', '', $c), array_keys($_POST['cols']));
        $vals = array_values($_POST['cols']);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
        $pdo->prepare("INSERT INTO `$table` ($colList) VALUES ($placeholders)")->execute($vals);
        $msg = "Row inserted successfully.";
        $action = 'browse';
    }

    // ── ALTER: ADD COLUMN ────────────────────────────────────────────────────
    if ($action === 'do_add_column' && $table && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $col      = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['col_name']);
        $type     = $_POST['col_type'];
        $null     = isset($_POST['col_null']) ? 'NULL' : 'NOT NULL';
        $default  = $_POST['col_default'] !== '' ? "DEFAULT '" . addslashes($_POST['col_default']) . "'" : '';
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $type $null $default");
        $msg = "Column '$col' added.";
        $action = 'structure';
    }

    // ── ALTER: DROP COLUMN ───────────────────────────────────────────────────
    if ($action === 'do_drop_column' && $table && isset($_GET['col'])) {
        $col = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['col']);
        $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$col`");
        $msg = "Column '$col' dropped.";
        $action = 'structure';
    }

    // ── ALTER: MODIFY COLUMN ─────────────────────────────────────────────────
    if ($action === 'do_modify_column' && $table && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $oldCol  = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['old_col']);
        $newCol  = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['new_col']);
        $type    = $_POST['col_type'];
        $null    = isset($_POST['col_null']) ? 'NULL' : 'NOT NULL';
        $default = $_POST['col_default'] !== '' ? "DEFAULT '" . addslashes($_POST['col_default']) . "'" : '';
        $pdo->exec("ALTER TABLE `$table` CHANGE `$oldCol` `$newCol` $type $null $default");
        $msg = "Column '$oldCol' modified.";
        $action = 'structure';
    }

    // ── DROP TABLE ──────────────────────────────────────────────────────────
    if ($action === 'do_drop_table' && $table) {
        $pdo->exec("DROP TABLE `$table`");
        $msg = "Table '$table' dropped.";
        $table  = '';
        $action = 'tables';
    }

    // ── TRUNCATE TABLE ──────────────────────────────────────────────────────
    if ($action === 'do_truncate' && $table) {
        $pdo->exec("TRUNCATE TABLE `$table`");
        $msg = "Table '$table' truncated.";
        $action = 'browse';
    }

    // ── CREATE TABLE ────────────────────────────────────────────────────────
    if ($action === 'do_create_table' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $tname = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table_name']);
        $cols  = [];
        foreach ($_POST['col_name'] as $i => $cname) {
            $cname = preg_replace('/[^a-zA-Z0-9_]/', '', $cname);
            if (!$cname) continue;
            $type = $_POST['col_type'][$i];
            $pk   = isset($_POST['col_pk'][$i]) ? ' PRIMARY KEY AUTO_INCREMENT' : '';
            $null = isset($_POST['col_null'][$i]) ? 'NULL' : 'NOT NULL';
            $cols[] = "`$cname` $type $null$pk";
        }
        if ($tname && $cols) {
            $pdo->exec("CREATE TABLE `$tname` (" . implode(', ', $cols) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $msg = "Table '$tname' created.";
        }
        $action = 'tables';
    }

    // ── RUN SQL ──────────────────────────────────────────────────────────────
    $sqlResult = null;
    $sqlError  = null;
    if ($action === 'run_sql' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql'])) {
        $sql = trim($_POST['sql']);
        try {
            if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)/i', $sql)) {
                $stmt = $pdo->query($sql);
                $sqlResult = $stmt->fetchAll();
            } else {
                $affected = $pdo->exec($sql);
                $msg = "Query OK — $affected row(s) affected.";
            }
        } catch (PDOException $e) {
            $sqlError = $e->getMessage();
        }
    }

    // ─── FETCH DATA ────────────────────────────────────────────────────────
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $columns = $rows = $primaryKey = [];
    $totalRows = 0;
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 25;
    $offset= ($page - 1) * $limit;

    if ($table && in_array($table, $tables)) {
        $columns = $pdo->query("SHOW FULL COLUMNS FROM `$table`")->fetchAll();
        // detect primary key
        foreach ($columns as $col) {
            if ($col['Key'] === 'PRI') { $primaryKey = $col['Field']; break; }
        }
        if (in_array($action, ['browse', 'delete_row', 'do_insert', 'do_truncate', 'do_add_column', 'do_drop_column', 'do_modify_column'])) {
            $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $rows = $pdo->query("SELECT * FROM `$table` LIMIT $limit OFFSET $offset")->fetchAll();
            if (!in_array($action, ['structure', 'insert'])) $action = 'browse';
        }
    }

    // Indexes for structure view
    $indexes = [];
    if ($table && in_array($action, ['structure'])) {
        $indexes = $pdo->query("SHOW INDEX FROM `$table`")->fetchAll();
    }

    // DB stats
    $dbStats = $pdo->query("SELECT 
        SUM(TABLE_ROWS) as total_rows,
        SUM(DATA_LENGTH + INDEX_LENGTH) as total_size
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE()")->fetch();

} catch (PDOException $e) {
    $error = $e->getMessage();
}

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function u(array $params): string {
    $base = array_filter($_GET, fn($k) => !in_array($k, array_keys($params)), ARRAY_FILTER_USE_KEY);
    return '?' . http_build_query(array_merge($base, $params));
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtBytes($b): string {
    if ($b >= 1073741824) return round($b/1073741824,2).' GB';
    if ($b >= 1048576)    return round($b/1048576,2).' MB';
    if ($b >= 1024)       return round($b/1024,2).' KB';
    return $b.' B';
}

// ─── RENDER ───────────────────────────────────────────────────────────────────
renderPage($action, $table, $tables, $columns, $rows, $primaryKey, $indexes, $totalRows, $page, $limit, $msg, $error, $sqlResult, $sqlError, $dbStats ?? []);

// ═════════════════════════════════════════════════════════════════════════════
// RENDER FUNCTIONS
// ═════════════════════════════════════════════════════════════════════════════

function renderLogin(?string $error): void { ?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DB Manager — Login</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0f14;--surface:#161b24;--border:#252c3a;--accent:#00e5a0;--accent2:#0066ff;--text:#e2e8f0;--muted:#6b7a99;--danger:#ff4466;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'IBM Plex Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:2.5rem;width:380px;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.logo{font-family:'IBM Plex Mono',monospace;font-size:1rem;color:var(--accent);letter-spacing:.1em;margin-bottom:.25rem;}
h1{font-size:1.4rem;font-weight:600;margin-bottom:.25rem;}
p{color:var(--muted);font-size:.85rem;margin-bottom:2rem;}
label{display:block;font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;}
input[type=password]{width:100%;background:#0d0f14;border:1px solid var(--border);color:var(--text);padding:.7rem 1rem;border-radius:5px;font-family:'IBM Plex Mono',monospace;font-size:.9rem;outline:none;transition:border .2s;}
input[type=password]:focus{border-color:var(--accent);}
.btn{width:100%;margin-top:1.2rem;padding:.75rem;background:var(--accent);color:#0d0f14;border:none;border-radius:5px;font-weight:600;font-size:.9rem;cursor:pointer;letter-spacing:.05em;transition:opacity .2s;}
.btn:hover{opacity:.85;}
.err{color:var(--danger);font-size:.8rem;margin-top:.75rem;text-align:center;}
</style></head><body>
<div class="card">
  <div class="logo">// DB_MANAGER</div>
  <h1><?= h(DB_NAME) ?></h1>
  <p>Enter the manager password to continue.</p>
  <form method="POST">
    <label>Password</label>
    <input type="password" name="dbm_password" autofocus>
    <button class="btn" type="submit">Authenticate →</button>
    <?php if ($error): ?><div class="err">⚠ <?= h($error) ?></div><?php endif; ?>
  </form>
</div>
</body></html>
<?php }

// ─────────────────────────────────────────────────────────────────────────────

function renderPage(string $action, string $table, array $tables, array $columns, array $rows, $primaryKey, array $indexes, int $totalRows, int $page, int $limit, string $msg, string $error, $sqlResult, $sqlError, array $dbStats): void {
$totalPages = $limit > 0 && $totalRows > 0 ? ceil($totalRows / $limit) : 1;
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DB Manager — <?= h(DB_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@300;400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0d0f14;--surface:#131720;--surface2:#181e2a;--border:#1f2736;--border2:#252d3f;
  --accent:#00e5a0;--accent-dim:#00c48a;--accent2:#3b82f6;--accent3:#f59e0b;
  --text:#dde4f0;--text2:#8a96b0;--text3:#566070;
  --danger:#f43f5e;--warning:#f59e0b;--success:#10b981;
  --font-mono:'IBM Plex Mono',monospace;--font-sans:'IBM Plex Sans',sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:var(--font-sans);font-size:.875rem;display:flex;height:100vh;overflow:hidden;}

/* ── Sidebar ── */
#sidebar{width:240px;min-width:240px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}
.sidebar-header{padding:1rem 1.25rem .75rem;border-bottom:1px solid var(--border);}
.sidebar-logo{font-family:var(--font-mono);font-size:.7rem;color:var(--accent);letter-spacing:.12em;text-transform:uppercase;margin-bottom:.2rem;}
.sidebar-db{font-size:.95rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sidebar-meta{font-size:.7rem;color:var(--text3);margin-top:.15rem;}
.sidebar-actions{padding:.6rem .75rem;border-bottom:1px solid var(--border);display:flex;gap:.4rem;}
.sidebar-actions a{font-size:.7rem;padding:.3rem .6rem;border-radius:4px;text-decoration:none;color:var(--text2);border:1px solid var(--border2);transition:all .15s;}
.sidebar-actions a:hover{background:var(--border2);color:var(--text);}
.sidebar-search{padding:.6rem .75rem;border-bottom:1px solid var(--border);}
.sidebar-search input{width:100%;background:var(--bg);border:1px solid var(--border2);color:var(--text);padding:.4rem .6rem;border-radius:4px;font-size:.75rem;font-family:var(--font-mono);outline:none;}
.sidebar-search input:focus{border-color:var(--accent);}
#table-list{overflow-y:auto;flex:1;}
.table-item{display:block;padding:.5rem 1.25rem;text-decoration:none;color:var(--text2);font-family:var(--font-mono);font-size:.75rem;border-left:2px solid transparent;transition:all .12s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.table-item:hover{background:var(--surface2);color:var(--text);border-left-color:var(--border2);}
.table-item.active{background:var(--surface2);color:var(--accent);border-left-color:var(--accent);}
.sidebar-footer{padding:.75rem 1rem;border-top:1px solid var(--border);font-size:.7rem;color:var(--text3);display:flex;justify-content:space-between;}
.sidebar-footer a{color:var(--text3);text-decoration:none;}
.sidebar-footer a:hover{color:var(--danger);}

/* ── Main ── */
#main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
.topbar{padding:.7rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:1rem;background:var(--surface);flex-shrink:0;}
.topbar-path{font-family:var(--font-mono);font-size:.75rem;color:var(--text3);}
.topbar-path span{color:var(--accent);}
.tab-nav{display:flex;gap:.1rem;margin-left:auto;}
.tab-nav a{font-size:.75rem;padding:.4rem .85rem;border-radius:4px;text-decoration:none;color:var(--text2);transition:all .12s;border:1px solid transparent;}
.tab-nav a:hover{background:var(--surface2);color:var(--text);}
.tab-nav a.active{background:var(--accent);color:#0d0f14;font-weight:600;}
.content{flex:1;overflow-y:auto;padding:1.5rem;}

/* ── Alerts ── */
.alert{padding:.6rem 1rem;border-radius:5px;font-size:.8rem;margin-bottom:1.25rem;font-family:var(--font-mono);}
.alert-success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:var(--success);}
.alert-error{background:rgba(244,63,94,.12);border:1px solid rgba(244,63,94,.3);color:var(--danger);}

/* ── Section titles ── */
.section-title{font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:.75rem;font-family:var(--font-mono);}

/* ── Table UI ── */
.tbl-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:6px;}
table.data{width:100%;border-collapse:collapse;font-family:var(--font-mono);font-size:.78rem;}
table.data thead tr{background:var(--surface2);}
table.data th{padding:.55rem .75rem;text-align:left;color:var(--text3);font-weight:500;letter-spacing:.06em;font-size:.7rem;text-transform:uppercase;border-bottom:1px solid var(--border);white-space:nowrap;}
table.data td{padding:.5rem .75rem;border-bottom:1px solid var(--border);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;}
table.data tr:last-child td{border-bottom:none;}
table.data tr:hover td{background:var(--surface2);}
.null-val{color:var(--text3);font-style:italic;}
.actions-col{display:flex;gap:.4rem;align-items:center;}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.45rem .9rem;border-radius:4px;border:none;cursor:pointer;font-family:var(--font-sans);font-size:.78rem;font-weight:500;text-decoration:none;transition:all .12s;letter-spacing:.02em;}
.btn-primary{background:var(--accent);color:#0d0f14;}
.btn-primary:hover{background:var(--accent-dim);}
.btn-secondary{background:transparent;color:var(--text2);border:1px solid var(--border2);}
.btn-secondary:hover{background:var(--surface2);color:var(--text);}
.btn-danger{background:transparent;color:var(--danger);border:1px solid rgba(244,63,94,.3);}
.btn-danger:hover{background:rgba(244,63,94,.1);}
.btn-sm{padding:.28rem .6rem;font-size:.72rem;}
.btn-icon{padding:.3rem .5rem;font-size:.8rem;}

/* ── Editable cells ── */
td[contenteditable=true]{cursor:text;outline:1px dashed var(--border2);}
td[contenteditable=true]:focus{outline:1px solid var(--accent);background:rgba(0,229,160,.05);}
.save-row-btn{font-size:.65rem;padding:.2rem .5rem;background:var(--accent);color:#0d0f14;border:none;border-radius:3px;cursor:pointer;display:none;}

/* ── Pagination ── */
.pagination{display:flex;gap:.4rem;align-items:center;margin-top:1rem;flex-wrap:wrap;}
.pagination a,.pagination span{padding:.35rem .65rem;border-radius:4px;font-size:.75rem;text-decoration:none;font-family:var(--font-mono);}
.pagination a{color:var(--text2);border:1px solid var(--border2);}
.pagination a:hover{background:var(--surface2);color:var(--text);}
.pagination .current{background:var(--accent);color:#0d0f14;font-weight:600;}
.pagination .info{color:var(--text3);font-size:.72rem;margin-left:.5rem;}

/* ── Forms ── */
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem;margin-bottom:1rem;}
.form-group{display:flex;flex-direction:column;gap:.3rem;}
.form-group label{font-size:.7rem;color:var(--text2);text-transform:uppercase;letter-spacing:.07em;}
.form-group input,.form-group select,.form-group textarea{background:var(--surface2);border:1px solid var(--border2);color:var(--text);padding:.55rem .75rem;border-radius:4px;font-family:var(--font-mono);font-size:.8rem;outline:none;transition:border .15s;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--accent);}
.form-group select option{background:var(--surface);}
textarea.sql-editor{width:100%;min-height:120px;resize:vertical;}
.checkbox-row{display:flex;align-items:center;gap:.5rem;font-size:.8rem;}
.checkbox-row input{width:auto;}

/* ── Key badges ── */
.badge{display:inline-block;padding:.15rem .4rem;border-radius:3px;font-size:.65rem;font-family:var(--font-mono);font-weight:600;letter-spacing:.05em;}
.badge-pk{background:rgba(245,158,11,.15);color:var(--accent3);border:1px solid rgba(245,158,11,.3);}
.badge-idx{background:rgba(59,130,246,.12);color:var(--accent2);border:1px solid rgba(59,130,246,.25);}
.badge-uni{background:rgba(0,229,160,.1);color:var(--accent);border:1px solid rgba(0,229,160,.25);}
.badge-null{background:rgba(255,255,255,.05);color:var(--text3);border:1px solid var(--border2);}

/* ── Stats cards ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem;margin-bottom:1.5rem;}
.stat-card{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:1rem;}
.stat-label{font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:.3rem;}
.stat-value{font-family:var(--font-mono);font-size:1.25rem;font-weight:600;color:var(--accent);}

/* ── SQL block ── */
.sql-block{background:var(--surface2);border:1px solid var(--border);border-radius:5px;padding:.75rem 1rem;font-family:var(--font-mono);font-size:.75rem;color:var(--text2);overflow-x:auto;white-space:pre;}

/* ── Column edit row ── */
.col-edit-row{display:grid;grid-template-columns:1.5fr 1.5fr 1fr 0.8fr auto;gap:.5rem;align-items:end;margin-bottom:.5rem;}

/* ── Modal ── */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:8px;padding:1.75rem;min-width:380px;max-width:540px;width:90%;}
.modal h3{margin-bottom:1rem;font-size:1rem;}
.modal-actions{display:flex;gap:.6rem;justify-content:flex-end;margin-top:1.25rem;}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:var(--bg);}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px;}
</style>
</head>
<body>

<!-- ── SIDEBAR ──────────────────────────────────────────────── -->
<nav id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">// db_manager</div>
    <div class="sidebar-db"><?= h(DB_NAME) ?></div>
    <div class="sidebar-meta"><?= h(DB_HOST) ?> · <?= count($tables) ?> tables · <?= fmtBytes((int)($dbStats['total_size'] ?? 0)) ?></div>
  </div>
  <div class="sidebar-actions">
    <a href="<?= u(['action'=>'tables','table'=>'']) ?>">Overview</a>
    <a href="<?= u(['action'=>'run_sql','table'=>'']) ?>">SQL</a>
    <a href="<?= u(['action'=>'create_table','table'=>'']) ?>">+ Table</a>
  </div>
  <div class="sidebar-search">
    <input type="text" id="tbl-search" placeholder="Search tables…" oninput="filterTables(this.value)">
  </div>
  <div id="table-list">
    <?php foreach ($tables as $t): ?>
    <a class="table-item <?= $t===$table?'active':'' ?>" href="<?= u(['table'=>$t,'action'=>'browse','page'=>1]) ?>"><?= h($t) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="sidebar-footer">
    <span><?= h(DB_USER) ?>@<?= h(DB_HOST) ?></span>
    <a href="?logout=1">Sign out</a>
  </div>
</nav>

<!-- ── MAIN ─────────────────────────────────────────────────── -->
<div id="main">
  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-path">
      <span><?= h(DB_NAME) ?></span>
      <?php if ($table): ?> / <span><?= h($table) ?></span><?php endif; ?>
    </div>
    <?php if ($table): ?>
    <div class="tab-nav">
      <a class="<?= $action==='browse'?'active':'' ?>" href="<?= u(['action'=>'browse','page'=>1]) ?>">Browse</a>
      <a class="<?= $action==='structure'?'active':'' ?>" href="<?= u(['action'=>'structure']) ?>">Structure</a>
      <a class="<?= $action==='insert'?'active':'' ?>" href="<?= u(['action'=>'insert']) ?>">Insert</a>
      <a class="<?= $action==='run_sql'?'active':'' ?>" href="<?= u(['action'=>'run_sql','sql_table'=>$table]) ?>">SQL</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Content -->
  <div class="content">
    <?php if ($msg): ?><div class="alert alert-success">✓ <?= h($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">✗ <?= h($error) ?></div><?php endif; ?>

    <?php
    // ── Dispatch views
    if ($action === 'tables') renderOverview($tables, $dbStats, $pdo);
    elseif ($action === 'browse' && $table) renderBrowse($table, $columns, $rows, $primaryKey, $totalRows, $page, $totalPages, $limit);
    elseif ($action === 'structure' && $table) renderStructure($table, $columns, $indexes);
    elseif ($action === 'insert' && $table) renderInsert($table, $columns);
    elseif ($action === 'run_sql') renderSQL($sqlResult, $sqlError, $table);
    elseif ($action === 'create_table') renderCreateTable();
    else renderOverview($tables, $dbStats, $pdo);
    ?>
  </div>
</div>

<!-- ── MODALS ── -->
<div class="modal-bg" id="modal-confirm">
  <div class="modal">
    <h3 id="modal-title">Confirm Action</h3>
    <p id="modal-body" style="color:var(--text2);font-size:.85rem;"></p>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <a id="modal-confirm-btn" class="btn btn-danger">Confirm</a>
    </div>
  </div>
</div>

<div class="modal-bg" id="modal-modify">
  <div class="modal">
    <h3>Modify Column</h3>
    <form method="POST" action="<?= u(['action'=>'do_modify_column']) ?>">
      <input type="hidden" name="old_col" id="mod_old_col">
      <div class="form-grid">
        <div class="form-group"><label>Column Name</label><input name="new_col" id="mod_new_col" required></div>
        <div class="form-group"><label>Type</label>
          <select name="col_type" id="mod_col_type"><?= columnTypeOptions() ?></select>
        </div>
        <div class="form-group"><label>Default</label><input name="col_default" id="mod_col_default"></div>
      </div>
      <div class="checkbox-row"><input type="checkbox" name="col_null" id="mod_col_null"><label for="mod_col_null">Allow NULL</label></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-modify')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function filterTables(q) {
  document.querySelectorAll('.table-item').forEach(a => {
    a.style.display = a.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
  });
}

function confirmAction(title, body, url) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').textContent  = body;
  document.getElementById('modal-confirm-btn').href  = url;
  document.getElementById('modal-confirm').classList.add('open');
}
function closeModal(id='modal-confirm') {
  document.getElementById(id).classList.remove('open');
}
document.getElementById('modal-confirm').addEventListener('click', function(e){
  if(e.target===this) closeModal();
});
document.getElementById('modal-modify').addEventListener('click', function(e){
  if(e.target===this) closeModal('modal-modify');
});

function openModify(col, type, def, nullable) {
  document.getElementById('mod_old_col').value    = col;
  document.getElementById('mod_new_col').value    = col;
  document.getElementById('mod_col_default').value= def;
  document.getElementById('mod_col_null').checked = nullable==='YES';
  // Try to match type
  const sel = document.getElementById('mod_col_type');
  for(let i=0;i<sel.options.length;i++){
    if(type.toUpperCase().startsWith(sel.options[i].value.toUpperCase())){
      sel.selectedIndex=i; break;
    }
  }
  document.getElementById('modal-modify').classList.add('open');
}

// Inline cell editing
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('td[contenteditable]').forEach(td => {
    td.addEventListener('focus', () => {
      const btn = td.closest('tr').querySelector('.save-row-btn');
      if(btn) btn.style.display='inline-block';
    });
  });
});

function saveRow(btn, table, pkCol, pkVal) {
  const row = btn.closest('tr');
  const cells = row.querySelectorAll('td[data-col]');
  const saves = [];
  cells.forEach(td => {
    saves.push(fetch('?action=ajax_save_cell', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({table, col:td.dataset.col, pk_col:pkCol, pk_val:pkVal, value:td.textContent})
    }));
  });
  Promise.all(saves).then(() => {
    btn.textContent='✓ Saved';
    btn.style.background='var(--success)';
    setTimeout(()=>{ btn.style.display='none'; btn.textContent='Save'; btn.style.background=''; }, 1800);
  });
}
</script>

</body></html>
<?php }

// ─────────────────────────────────────────────────────────────────────────────

function renderOverview(array $tables, array $dbStats, PDO $pdo): void {
    $tableStats = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH+INDEX_LENGTH as size, ENGINE, TABLE_COLLATION
        FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll();
    ?>
    <div class="section-title">Database Overview</div>
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-label">Tables</div><div class="stat-value"><?= count($tables) ?></div></div>
      <div class="stat-card"><div class="stat-label">Total Rows (est.)</div><div class="stat-value"><?= number_format((int)($dbStats['total_rows']??0)) ?></div></div>
      <div class="stat-card"><div class="stat-label">Database Size</div><div class="stat-value"><?= fmtBytes((int)($dbStats['total_size']??0)) ?></div></div>
      <div class="stat-card"><div class="stat-label">DB Name</div><div class="stat-value" style="font-size:.85rem"><?= h(DB_NAME) ?></div></div>
    </div>
    <div class="tbl-wrap">
    <table class="data">
      <thead><tr><th>Table</th><th>Rows (est.)</th><th>Size</th><th>Engine</th><th>Collation</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($tableStats as $ts): ?>
      <tr>
        <td><a href="<?= u(['table'=>$ts['TABLE_NAME'],'action'=>'browse','page'=>1]) ?>" style="color:var(--accent);text-decoration:none"><?= h($ts['TABLE_NAME']) ?></a></td>
        <td><?= number_format((int)$ts['TABLE_ROWS']) ?></td>
        <td><?= fmtBytes((int)$ts['size']) ?></td>
        <td><?= h($ts['ENGINE']) ?></td>
        <td><?= h($ts['TABLE_COLLATION']) ?></td>
        <td>
          <div class="actions-col">
            <a class="btn btn-sm btn-secondary" href="<?= u(['table'=>$ts['TABLE_NAME'],'action'=>'structure']) ?>">Structure</a>
            <a class="btn btn-sm btn-secondary" href="<?= u(['table'=>$ts['TABLE_NAME'],'action'=>'insert']) ?>">Insert</a>
            <button class="btn btn-sm btn-danger" onclick="confirmAction('Truncate Table','Delete all rows from <?= h($ts['TABLE_NAME']) ?>? This cannot be undone.','<?= u(['action'=>'do_truncate','table'=>$ts['TABLE_NAME']]) ?>')">Truncate</button>
            <button class="btn btn-sm btn-danger" onclick="confirmAction('Drop Table','Permanently drop table <?= h($ts['TABLE_NAME']) ?>?','<?= u(['action'=>'do_drop_table','table'=>$ts['TABLE_NAME']]) ?>')">Drop</button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php }

// ─────────────────────────────────────────────────────────────────────────────

function renderBrowse(string $table, array $columns, array $rows, $primaryKey, int $totalRows, int $page, int $totalPages, int $limit): void { ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem;">
      <div class="section-title" style="margin:0">
        <?= h($table) ?> — <span style="color:var(--text2)"><?= number_format($totalRows) ?> rows</span>
      </div>
      <a class="btn btn-primary btn-sm" href="<?= u(['action'=>'insert']) ?>">+ Insert Row</a>
    </div>
    <div class="tbl-wrap">
    <table class="data">
      <thead><tr>
        <?php if ($primaryKey): ?><th></th><?php endif; ?>
        <?php foreach ($columns as $col): ?><th><?= h($col['Field']) ?></th><?php endforeach; ?>
        <th>Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
        $pkVal = $primaryKey ? $row[$primaryKey] : null;
      ?>
      <tr>
        <?php if ($primaryKey): ?>
        <td><button class="save-row-btn btn btn-sm" onclick="saveRow(this,'<?= h($table) ?>','<?= h($primaryKey) ?>','<?= h($pkVal) ?>')">Save</button></td>
        <?php endif; ?>
        <?php foreach ($columns as $col):
          $val = $row[$col['Field']];
          $editable = $primaryKey && $col['Field'] !== $primaryKey ? 'contenteditable="true"' : '';
        ?>
        <td <?= $editable ?> data-col="<?= h($col['Field']) ?>">
          <?php if ($val === null): ?><span class="null-val">NULL</span>
          <?php else: echo h($val); endif; ?>
        </td>
        <?php endforeach; ?>
        <td>
          <?php if ($primaryKey && $pkVal !== null): ?>
          <div class="actions-col">
            <button class="btn btn-sm btn-danger" onclick="confirmAction('Delete Row','Delete row where <?= h($primaryKey) ?>=<?= h($pkVal) ?>?','<?= u(['action'=>'delete_row','pk_col'=>$primaryKey,'pk_val'=>$pkVal]) ?>')">Delete</button>
          </div>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?><a href="<?= u(['page'=>1]) ?>">«</a><a href="<?= u(['page'=>$page-1]) ?>">‹</a><?php endif; ?>
      <?php
      $range = range(max(1,$page-3), min($totalPages,$page+3));
      foreach ($range as $p):
      ?>
        <?php if ($p==$page): ?><span class="current"><?= $p ?></span>
        <?php else: ?><a href="<?= u(['page'=>$p]) ?>"><?= $p ?></a><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($page < $totalPages): ?><a href="<?= u(['page'=>$page+1]) ?>">›</a><a href="<?= u(['page'=>$totalPages]) ?>">»</a><?php endif; ?>
      <span class="info">Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($totalRows) ?> rows</span>
    </div>
    <?php endif; ?>
<?php }

// ─────────────────────────────────────────────────────────────────────────────

function renderStructure(string $table, array $columns, array $indexes): void { ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem;">
      <div class="section-title" style="margin:0">Structure: <?= h($table) ?></div>
    </div>

    <div class="tbl-wrap" style="margin-bottom:1.5rem">
    <table class="data">
      <thead><tr><th>#</th><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($columns as $i => $col): ?>
      <tr>
        <td style="color:var(--text3)"><?= $i+1 ?></td>
        <td><?= h($col['Field']) ?></td>
        <td><?= h($col['Type']) ?></td>
        <td><?php if($col['Null']==='YES'):?><span class="badge badge-null">YES</span><?php else:?><span style="color:var(--text3)">NO</span><?php endif;?></td>
        <td>
          <?php if($col['Key']==='PRI') echo '<span class="badge badge-pk">PRI</span>';
          elseif($col['Key']==='UNI') echo '<span class="badge badge-uni">UNI</span>';
          elseif($col['Key']==='MUL') echo '<span class="badge badge-idx">IDX</span>'; ?>
        </td>
        <td><?= h($col['Default'] ?? '') ?></td>
        <td style="color:var(--text3);font-size:.72rem"><?= h($col['Extra']) ?></td>
        <td>
          <div class="actions-col">
            <button class="btn btn-sm btn-secondary" onclick="openModify('<?= h($col['Field']) ?>','<?= h($col['Type']) ?>','<?= h($col['Default']??'') ?>','<?= h($col['Null']) ?>')">Edit</button>
            <?php if($col['Key']!=='PRI'):?>
            <button class="btn btn-sm btn-danger" onclick="confirmAction('Drop Column','Drop column &quot;<?= h($col['Field']) ?>&quot; from <?= h($table) ?>?','<?= u(['action'=>'do_drop_column','col'=>$col['Field']]) ?>')">Drop</button>
            <?php endif;?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Add column -->
    <div class="section-title">Add Column</div>
    <form method="POST" action="<?= u(['action'=>'do_add_column']) ?>">
      <div class="form-grid">
        <div class="form-group"><label>Column Name</label><input name="col_name" required></div>
        <div class="form-group"><label>Type</label><select name="col_type"><?= columnTypeOptions() ?></select></div>
        <div class="form-group"><label>Default Value</label><input name="col_default" value=""></div>
      </div>
      <div class="checkbox-row" style="margin-bottom:.85rem"><input type="checkbox" name="col_null" id="add_null"><label for="add_null">Allow NULL</label></div>
      <button type="submit" class="btn btn-primary">Add Column</button>
    </form>

    <?php if ($indexes): ?>
    <div class="section-title" style="margin-top:1.5rem">Indexes</div>
    <div class="tbl-wrap">
    <table class="data">
      <thead><tr><th>Key Name</th><th>Column</th><th>Non-Unique</th><th>Index Type</th></tr></thead>
      <tbody>
      <?php foreach ($indexes as $idx): ?>
      <tr>
        <td><?= h($idx['Key_name']) ?></td>
        <td><?= h($idx['Column_name']) ?></td>
        <td><?= $idx['Non_unique'] ? 'Yes' : '<span style="color:var(--accent)">No (Unique)</span>' ?></td>
        <td><?= h($idx['Index_type']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
<?php }

// ─────────────────────────────────────────────────────────────────────────────

function renderInsert(string $table, array $columns): void { ?>
    <div class="section-title">Insert Row into <?= h($table) ?></div>
    <form method="POST" action="<?= u(['action'=>'do_insert']) ?>">
      <div class="form-grid">
      <?php foreach ($columns as $col):
        if ($col['Extra'] === 'auto_increment') continue;
      ?>
        <div class="form-group">
          <label><?= h($col['Field']) ?> <span style="color:var(--text3)"><?= h($col['Type']) ?></span></label>
          <input name="cols[<?= h($col['Field']) ?>]" placeholder="<?= h($col['Null']==='YES'?'NULL':'') ?>">
        </div>
      <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-primary">Insert Row</button>
    </form>
<?php }

// ─────────────────────────────────────────────────────────────────────────────

function renderSQL($result, $sqlError, string $table): void {
    $prefill = $table ? "SELECT * FROM `$table` LIMIT 100;" : "SELECT * FROM `" . DB_NAME . "`.`your_table` LIMIT 100;";
?>
    <div class="section-title">SQL Query</div>
    <form method="POST" action="<?= u(['action'=>'run_sql']) ?>">
      <div class="form-group" style="margin-bottom:.75rem">
        <textarea name="sql" class="sql-editor form-group"><?= h($_POST['sql'] ?? $prefill) ?></textarea>
      </div>
      <button type="submit" class="btn btn-primary">Run Query ▶</button>
    </form>

    <?php if ($sqlError): ?>
      <div class="alert alert-error" style="margin-top:1rem">✗ <?= h($sqlError) ?></div>
    <?php elseif (is_array($result) && count($result)): ?>
      <div style="margin-top:1rem;color:var(--text2);font-size:.75rem;margin-bottom:.5rem"><?= count($result) ?> row(s) returned</div>
      <div class="tbl-wrap">
      <table class="data">
        <thead><tr><?php foreach (array_keys($result[0]) as $k): ?><th><?= h($k) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($result as $row): ?>
          <tr><?php foreach ($row as $v): ?>
            <td><?= $v === null ? '<span class="null-val">NULL</span>' : h($v) ?></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php elseif (is_array($result) && count($result) === 0): ?>
      <div class="alert alert-success" style="margin-top:1rem">Query returned 0 rows.</div>
    <?php endif; ?>
<?php }

// ─────────────────────────────────────────────────────────────────────────────

function renderCreateTable(): void { ?>
    <div class="section-title">Create New Table</div>
    <form method="POST" action="<?= u(['action'=>'do_create_table']) ?>">
      <div class="form-group" style="max-width:280px;margin-bottom:1rem">
        <label>Table Name</label>
        <input name="table_name" required>
      </div>
      <div id="col-rows">
        <?php for ($i=0;$i<3;$i++): ?>
        <div class="col-edit-row">
          <div class="form-group"><label><?= $i===0?'Column Name':'' ?></label><input name="col_name[]" placeholder="column_name" <?= $i===0?'value="id"':'' ?>></div>
          <div class="form-group"><label><?= $i===0?'Type':'' ?></label><select name="col_type[]"><?= columnTypeOptions($i===0?'INT':'VARCHAR(255)') ?></select></div>
          <div class="form-group"><label><?= $i===0?'Nullable':'' ?></label>
            <div class="checkbox-row" style="margin-top:.55rem"><input type="checkbox" name="col_null[<?= $i ?>]" <?= $i!==0?'checked':'' ?>><label>NULL</label></div>
          </div>
          <div class="form-group"><label><?= $i===0?'Primary Key':'' ?></label>
            <div class="checkbox-row" style="margin-top:.55rem"><input type="checkbox" name="col_pk[<?= $i ?>]" <?= $i===0?'checked':'' ?>><label>PK / AI</label></div>
          </div>
          <div class="form-group"><label><?= $i===0?'&nbsp;':'' ?></label>
            <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.col-edit-row').remove()" style="margin-top:0">✕</button>
          </div>
        </div>
        <?php endfor; ?>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" style="margin-bottom:1rem" onclick="addColRow()">+ Add Column</button><br>
      <button type="submit" class="btn btn-primary">Create Table</button>
    </form>
<script>
function addColRow(){
  const tpl=`<div class="col-edit-row">
    <div class="form-group"><input name="col_name[]" placeholder="column_name"></div>
    <div class="form-group"><select name="col_type[]"><?= addslashes(columnTypeOptions()) ?></select></div>
    <div class="form-group"><div class="checkbox-row" style="margin-top:.55rem"><input type="checkbox" name="col_null[]" checked><label>NULL</label></div></div>
    <div class="form-group"><div class="checkbox-row" style="margin-top:.55rem"><input type="checkbox" name="col_pk[]"><label>PK/AI</label></div></div>
    <div class="form-group"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.col-edit-row').remove()">✕</button></div>
  </div>`;
  document.getElementById('col-rows').insertAdjacentHTML('beforeend',tpl);
}
</script>
<?php }

// ─────────────────────────────────────────────────────────────────────────────

function columnTypeOptions(string $selected = 'VARCHAR(255)'): string {
    $types = ['INT','BIGINT','TINYINT','SMALLINT','MEDIUMINT','FLOAT','DOUBLE','DECIMAL(10,2)',
              'VARCHAR(255)','VARCHAR(100)','VARCHAR(50)','CHAR(36)','TEXT','MEDIUMTEXT','LONGTEXT',
              'DATE','DATETIME','TIMESTAMP','TIME','YEAR',
              'BOOLEAN','JSON','BLOB','MEDIUMBLOB'];
    $out = '';
    foreach ($types as $t) {
        $sel = stripos($selected, $t) === 0 ? ' selected' : '';
        $out .= "<option value=\"$t\"$sel>$t</option>";
    }
    return $out;
}