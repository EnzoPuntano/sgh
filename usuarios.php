<?php
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/auth.php';

requiereLogin();

$rolActual = $_SESSION['usuario']['rol'];

if (!in_array($rolActual, ['Gerente', 'Recepcionista'], true)) {
    http_response_code(403);
    exit('No tenés permiso para gestionar usuarios.');
}

$error = '';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'crear') {
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $rolId = (int)($_POST['rol_id'] ?? 0);

            $stmt = $pdo->prepare(
                'SELECT id, nombre FROM roles WHERE id = ?'
            );
            $stmt->execute([$rolId]);
            $rolNuevo = $stmt->fetch();

            if ($nombre === '' || $apellido === '' ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                !preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username) ||
                strlen($password) < 8 || !$rolNuevo) {
                throw new Exception(
                    'Completá los campos correctamente. La contraseña debe tener al menos 8 caracteres.'
                );
            }

            // La recepcionista solamente puede crear clientes.
            if ($rolActual === 'Recepcionista' &&
                $rolNuevo['nombre'] !== 'Cliente') {
                throw new Exception(
                    'La recepcionista solamente puede registrar clientes.'
                );
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO usuarios
                (nombre, apellido, email, username, password_hash, rol_id)
                VALUES (?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $nombre, $apellido, $email, $username, $hash, $rolId
            ]);

            $mensaje = 'Usuario creado correctamente.';
        }

        if ($accion === 'estado') {
            if ($rolActual !== 'Gerente') {
                throw new Exception(
                    'Solo el gerente puede activar o desactivar usuarios.'
                );
            }

            $id = (int)($_POST['id'] ?? 0);
            $activo = (int)($_POST['activo'] ?? -1);

            if ($id < 1 || !in_array($activo, [0, 1], true)) {
                throw new Exception('Datos incorrectos.');
            }

            if ($id === (int)$_SESSION['usuario']['id'] && $activo === 0) {
                throw new Exception(
                    'No podés desactivar tu propio usuario.'
                );
            }

            $stmt = $pdo->prepare(
                'SELECT u.id, r.nombre AS rol
                 FROM usuarios u JOIN roles r ON r.id = u.rol_id
                 WHERE u.id = ?'
            );
            $stmt->execute([$id]);
            $objetivo = $stmt->fetch();

            if (!$objetivo) {
                throw new Exception('Usuario no encontrado.');
            }

            if ($objetivo['rol'] === 'Gerente') {
                throw new Exception(
                    'No se puede modificar el estado de un gerente desde esta pantalla.'
                );
            }

            $stmt = $pdo->prepare(
                'UPDATE usuarios SET activo = ? WHERE id = ?'
            );
            $stmt->execute([$activo, $id]);

            $mensaje = 'Estado del usuario actualizado.';
        }

    } catch (PDOException $e) {
        $error = 'No se pudo guardar. El usuario o el correo podrían estar registrados.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$roles = $pdo->query(
    'SELECT id, nombre FROM roles ORDER BY id'
)->fetchAll();

$usuarios = $pdo->query(
    'SELECT u.id, u.nombre, u.apellido, u.email,
            u.username, u.activo, r.nombre AS rol
     FROM usuarios u
     JOIN roles r ON r.id = u.rol_id
     ORDER BY u.id DESC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuarios | SGH</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<header class="topbar">
    <h1>SGH | Usuarios</h1>
    <a href="index.php">Volver al panel</a>
</header>

<main class="dashboard">
    <h2>Gestión de usuarios</h2>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($mensaje): ?>
        <p><?= htmlspecialchars($mensaje) ?></p>
    <?php endif; ?>

    <article class="menu-card">
        <h3>Registrar usuario</h3>

        <form method="POST">
            <input type="hidden" name="accion" value="crear">

            <label>Nombre</label>
            <input name="nombre" required maxlength="100">

            <label>Apellido</label>
            <input name="apellido" required maxlength="100">

            <label>Correo electrónico</label>
            <input type="email" name="email" required maxlength="150">

            <label>Nombre de usuario</label>
            <input name="username" required minlength="3" maxlength="50"
                   pattern="[a-zA-Z0-9_.-]+">

            <label>Contraseña (mínimo 8 caracteres)</label>
            <input type="password" name="password"
                   minlength="8" required autocomplete="new-password">

            <label>Rol</label>
            <select name="rol_id" required>
                <?php foreach ($roles as $r): ?>
                    <?php
                    if ($rolActual === 'Recepcionista' &&
                        $r['nombre'] !== 'Cliente') {
                        continue;
                    }
                    ?>
                    <option value="<?= (int)$r['id'] ?>">
                        <?= htmlspecialchars($r['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Crear usuario</button>
        </form>
    </article>

    <h3>Usuarios registrados</h3>

    <section class="menu-grid">
        <?php foreach ($usuarios as $u): ?>
            <article class="menu-card">
                <h3><?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido']) ?></h3>
                <p>Usuario: <?= htmlspecialchars($u['username']) ?></p>
                <p>Correo: <?= htmlspecialchars($u['email']) ?></p>
                <p>Rol: <?= htmlspecialchars($u['rol']) ?></p>
                <p>Estado: <?= $u['activo'] ? 'Activo' : 'Inactivo' ?></p>

                <?php if ($rolActual === 'Gerente' && $u['rol'] !== 'Gerente'): ?>
                    <form method="POST">
                        <input type="hidden" name="accion" value="estado">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="activo"
                               value="<?= $u['activo'] ? 0 : 1 ?>">
                        <button type="submit">
                            <?= $u['activo'] ? 'Desactivar' : 'Activar' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
