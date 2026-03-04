<?php
// auth/login.php (UI modernizada)
ini_set('default_charset', 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../auth.php'; // debe crear $pdo + helpers + session
$BASE = $GLOBALS['BASE_URL'] ?? '';

// Si ya está logueado
if (function_exists('current_user') && current_user()) {
  redirect($BASE . '/index.php');
}

$err = null;

/**
 * Intenta traer usuario desde una tabla, de forma robusta.
 * Retorna array normalizado o null.
 */
function fetch_user_from_table(PDO $pdo, string $table, string $username): ?array {
  // Verifica si existe la tabla
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
  ");
  $st->execute([$table]);
  if ((int)$st->fetchColumn() === 0) return null;

  // Obtener columnas reales
  $colsSt = $pdo->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
  ");
  $colsSt->execute([$table]);
  $realCols = array_map('strtolower', $colsSt->fetchAll(PDO::FETCH_COLUMN));

  // Helper para elegir columna existente
  $pick = function(array $cands) use ($realCols) {
    foreach ($cands as $c) if (in_array(strtolower($c), $realCols, true)) return $c;
    return null;
  };

  $colId   = $pick(['id']);
  $colUser = $pick(['username','user','usuario']);
  $colPass = $pick(['password','pass','clave']);
  $colRol  = $pick(['rol','role']);
  $colNom  = $pick(['nombre','nombres']);
  $colApe  = $pick(['apellido','apellidos']);
  $colSeal = $pick(['sello_path','firma','signature_path']);

  if (!$colId || !$colUser || !$colPass) return null;

  $select = [];
  $select[] = "$colId AS id";
  $select[] = "$colUser AS username";
  $select[] = "$colPass AS password";
  if ($colRol)  $select[] = "$colRol AS rol";
  if ($colNom)  $select[] = "$colNom AS nombre";
  if ($colApe)  $select[] = "$colApe AS apellido";
  if ($colSeal) $select[] = "$colSeal AS sello_path";

  // Nota: el nombre de tabla se usa “tal cual” (viene controlado por el código)
  $sql = "SELECT " . implode(", ", $select) . " FROM {$table} WHERE {$colUser} = ? LIMIT 1";
  $st2 = $pdo->prepare($sql);
  $st2->execute([$username]);
  $row = $st2->fetch(PDO::FETCH_ASSOC);

  if (!$row) return null;

  if (!isset($row['rol']) || $row['rol'] === null || $row['rol'] === '') {
    $row['rol'] = $row['role'] ?? null;
  }

  return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['_csrf'] ?? '');
  if (function_exists('csrf_check') && !csrf_check($token)) {
    $err = 'Sesión inválida. Recarga la página e intenta de nuevo.';
  } else {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
      $err = 'Ingresa usuario y contraseña.';
    } else {
      try {
        // 1) users
        $row = fetch_user_from_table($pdo, 'users', $username);

        // 2) hosp_usuarios
        if (!$row) {
          $row = fetch_user_from_table($pdo, 'hosp_usuarios', $username);
        }

        if (!$row || !password_verify($password, (string)$row['password'])) {
          $err = 'Usuario o contraseña incorrectos.';
        } else {
          if (empty($row['rol'])) $row['rol'] = 'viewer';
          login_user($row);
          if (function_exists('flash_set')) flash_set('success', 'Bienvenido.');
          redirect($BASE . '/index.php');
        }
      } catch (Throwable $e) {
        $err = 'Error al iniciar sesión. Revisa la base de datos / logs.';
      }
    }
  }
}

// helpers fallback por si acaso (si no existe e() en tu proyecto)
if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Iniciar sesión | Evo/Prx</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{
      --rm-primary:#0b5ed7;
      --rm-accent:#16a34a;
      --rm-dark:#0b1220;
    }

    body{
      min-height:100vh;
      background:
        radial-gradient(1200px 700px at 20% 15%, rgba(22,163,74,.18), transparent 60%),
        radial-gradient(900px 600px at 80% 30%, rgba(11,94,215,.20), transparent 55%),
        linear-gradient(180deg, #f8fafc, #eef2ff);
      display:flex;
      align-items:center;
    }

    .login-shell{
      width: min(980px, 96vw);
      margin: 0 auto;
    }

    .login-card{
      border:0;
      border-radius: 18px;
      overflow:hidden;
      box-shadow: 0 20px 60px rgba(2,6,23,.12);
      background: rgba(255,255,255,.82);
      backdrop-filter: blur(8px);
    }

    .brand-side{
      background:
        linear-gradient(135deg, rgba(11,94,215,.95), rgba(22,163,74,.88));
      color:#fff;
      padding: 34px;
      position:relative;
      overflow:hidden;
    }
    .brand-side:before{
      content:"";
      position:absolute;
      inset:-120px;
      background:
        radial-gradient(circle at 20% 20%, rgba(255,255,255,.22), transparent 45%),
        radial-gradient(circle at 80% 40%, rgba(255,255,255,.14), transparent 50%);
      transform: rotate(10deg);
    }
    .brand-content{
      position:relative;
      z-index:2;
    }

    .rm-logo{
      width: 210px;
      height:auto;
      filter: drop-shadow(0 10px 20px rgba(0,0,0,.18));
      border-radius: 10px;
      background: rgba(255,255,255,.75);
      padding: 10px 12px;
    }

    .hint-pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(255,255,255,.16);
      border: 1px solid rgba(255,255,255,.22);
      font-size: .9rem;
    }

    .form-side{
      padding: 34px;
    }

    .input-icon{
      position:absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      opacity:.65;
    }
    .form-control.iconed{
      padding-left: 42px;
      height: 44px;
      border-radius: 12px;
    }
    .btn-login{
      height: 44px;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--rm-primary), #2563eb);
      border:0;
      box-shadow: 0 10px 24px rgba(37,99,235,.22);
    }
    .btn-login:hover{ filter: brightness(1.03); }

    .subtle{
      color:#475569;
    }

    @media (max-width: 991px){
      .brand-side{ display:none; }
      .form-side{ padding: 26px; }
    }
  </style>
</head>

<body>
  <div class="login-shell">
    <div class="card login-card">
      <div class="row g-0">
        <!-- Lado branding -->
        <div class="col-lg-5 brand-side">
          <div class="brand-content">
            <img class="rm-logo" src="https://realmedic.com.ec/logo-realmedic.jpg" alt="Realmedic">
            <div class="mt-4">
              <h4 class="mb-2">Sistema Evo/Prx</h4>
              <p class="mb-3" style="opacity:.92">
                Acceso seguro para residentes y administración. Mantenga su información protegida.
              </p>
              <div class="hint-pill">
                <span>🔒</span>
                <span>Sesión cifrada + control de roles</span>
              </div>
            </div>
            <div class="mt-4" style="opacity:.9; font-size:.95rem;">
              <div class="fw-semibold">Tips</div>
              <ul class="mb-0 ps-3">
                <li>Use su usuario asignado.</li>
                <li>Si olvidó la clave, solicite reinicio al admin.</li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Lado formulario -->
        <div class="col-lg-7">
          <div class="form-side">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <div>
                <h5 class="mb-1">Iniciar sesión</h5>
                <div class="subtle small">Ingrese sus credenciales para continuar</div>
              </div>
              <span class="badge text-bg-light border">Realmedic</span>
            </div>

            <?php if (file_exists(__DIR__ . '/../partials/flash.php')): ?>
              <?php include __DIR__ . '/../partials/flash.php'; ?>
            <?php endif; ?>

            <?php if ($err): ?>
              <div class="alert alert-danger"><?= e($err) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off" class="mt-3">
              <?php if (function_exists('csrf_token')): ?>
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <?php endif; ?>

              <div class="mb-3 position-relative">
                <span class="input-icon">👤</span>
                <label class="form-label small mb-1">Usuario</label>
                <input class="form-control iconed" name="username" required placeholder="Ej: residente01">
              </div>

              <div class="mb-3 position-relative">
                <span class="input-icon">🔑</span>
                <label class="form-label small mb-1">Contraseña</label>
                <input type="password" class="form-control iconed" name="password" required placeholder="••••••••">
              </div>

              <button class="btn btn-login text-white w-100 mt-2">Entrar</button>

              <div class="d-flex justify-content-between align-items-center mt-3 small">
                <div class="text-muted">
                  ¿Primera vez? Admin: <b>/auth/initial_user.php</b>
                </div>
                <a class="text-decoration-none" href="<?= e($BASE) ?>/">← Volver</a>
              </div>
            </form>

            <div class="mt-4 p-3 border rounded-3 bg-white">
              <div class="small text-muted">
                Si su usuario está en <b>users</b> o <b>hosp_usuarios</b>, este login lo valida automáticamente.
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="text-center small text-muted mt-3">
      © <?= date('Y') ?> Realmedic · Evo/Prx
    </div>
  </div>
</body>
</html>