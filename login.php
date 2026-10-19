<?php
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/auth.php';

if (isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        'SELECT u.id, u.nombre, u.apellido, u.username,
                u.password_hash, r.nombre AS rol
         FROM usuarios u
         INNER JOIN roles r ON r.id = u.rol_id
         WHERE u.username = ? AND u.activo = 1
         LIMIT 1'
    );

    $stmt->execute([$username]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($password, $usuario['password_hash'])) {
        session_regenerate_id(true);

        $_SESSION['usuario'] = [
            'id' => $usuario['id'],
            'nombre' => $usuario['nombre'],
            'apellido' => $usuario['apellido'],
            'username' => $usuario['username'],
            'rol' => $usuario['rol']
        ];

        header('Location: index.php');
        exit;
    }

    $error = 'Usuario o contraseña incorrectos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar | SGH</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="login-body">
    <main class="login-card">
        <h1>SGH</h1>
        <p>Sistema de Gestión Hotelera</p>

        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <label for="username">Usuario</label>
            <input id="username" name="username"
                   required autocomplete="username">

            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password"
                   required autocomplete="current-password">

            <button type="submit">Ingresar</button>
        </form>
    </main>
</body>
</html>
