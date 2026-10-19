
<?php
require_once __DIR__ . '/config/auth.php';
requiereLogin();

$usuario = $_SESSION['usuario'];

$opciones = [
    'Gerente' => [
        'Habitaciones' => 'habitaciones.php',
        'Reservas' => 'reservas.php',
        'Usuarios' => 'usuarios.php',
        'Limpieza' => 'limpieza.php',
        'Mantenimiento' => 'mantenimiento.php',
    ],
    'Recepcionista' => [
        'Habitaciones' => 'habitaciones.php',
        'Reservas' => 'reservas.php',
    ],
    'Cliente' => [
        'Consultar habitaciones' => 'habitaciones.php',
        'Mis reservas' => 'reservas.php',
    ],
    'Limpieza' => [
        'Tareas de limpieza' => 'limpieza.php',
    ],
    'Mantenimiento' => [
        'Incidencias de mantenimiento' => 'mantenimiento.php',
    ],
];

$menu = $opciones[$usuario['rol']] ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel SGH</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <header class="topbar">
        <h1>SGH | Hotel</h1>
        <a href="logout.php">Cerrar sesión</a>
    </header>

    <main class="dashboard">
        <h2>¡Bienvenido/a, <?= htmlspecialchars($usuario['nombre']) ?>!</h2>
        <p>Rol: <?= htmlspecialchars($usuario['rol']) ?></p>

        <h3>Menú principal</h3>

        <section class="menu-grid">
            <?php foreach ($menu as $nombre => $archivo): ?>
                <a href="<?= htmlspecialchars($archivo) ?>"
                   class="menu-card"
                   style="text-decoration: none; color: inherit;">
                    <h3><?= htmlspecialchars($nombre) ?></h3>
                    <p>Ingresar a <?= strtolower(htmlspecialchars($nombre)) ?>.</p>
                </a>
            <?php endforeach; ?>
        </section>
    </main>
</body>
</html>
