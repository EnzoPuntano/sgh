
<?php
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/auth.php';

requiereLogin();

$rol = $_SESSION['usuario']['rol'];
$puedeGestionar = in_array($rol, ['Gerente', 'Recepcionista'], true);

if (!in_array($rol, ['Gerente', 'Recepcionista', 'Cliente'], true)) {
    http_response_code(403);
    exit('No tenés permiso para consultar habitaciones.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$puedeGestionar) {
        http_response_code(403);
        exit('No tenés permiso para modificar habitaciones.');
    }

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'crear') {
            $numero = trim($_POST['numero'] ?? '');
            $tipo = $_POST['tipo'] ?? '';
            $capacidad = (int)($_POST['capacidad'] ?? 0);
            $precio = (float)($_POST['precio'] ?? 0);

            $tipos = ['Individual', 'Doble', 'Triple', 'Suite', 'Familiar'];

            if ($numero === '' || !in_array($tipo, $tipos, true)
                || $capacidad < 1 || $precio <= 0) {
                throw new Exception('Completá correctamente todos los campos.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO habitaciones (numero, tipo, capacidad, precio)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$numero, $tipo, $capacidad, $precio]);

        } elseif ($accion === 'estado') {
            $id = (int)($_POST['id'] ?? 0);
            $estado = $_POST['estado'] ?? '';

            $estados = ['Disponible', 'Ocupada', 'Limpieza', 'Mantenimiento'];

            if ($id < 1 || !in_array($estado, $estados, true)) {
                throw new Exception('Datos incorrectos.');
            }

            $stmt = $pdo->prepare(
                'UPDATE habitaciones SET estado = ? WHERE id = ?'
            );
            $stmt->execute([$estado, $id]);

        } else {
            throw new Exception('Acción no válida.');
        }

        header('Location: habitaciones.php');
        exit;

    } catch (PDOException $e) {
        $error = 'No se pudo guardar. Verificá que el número no esté repetido.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($rol === 'Cliente') {
    $habitaciones = $pdo->query(
        "SELECT * FROM habitaciones
         WHERE estado = 'Disponible'
         ORDER BY numero"
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $habitaciones = $pdo->query(
        'SELECT * FROM habitaciones ORDER BY numero'
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Habitaciones | SGH</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<header class="topbar">
    <h1>SGH | Habitaciones</h1>
    <a href="index.php">Volver al panel</a>
</header>

<main class="dashboard">
    <h2><?= $puedeGestionar ? 'Gestión de habitaciones' : 'Habitaciones disponibles' ?></h2>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($puedeGestionar): ?>
        <article class="menu-card">
            <h3>Agregar habitación</h3>
            <form method="POST">
                <input type="hidden" name="accion" value="crear">

                <label>Número</label>
                <input name="numero" required maxlength="10">

                <label>Tipo</label>
                <select name="tipo" required>
                    <option value="Individual">Individual</option>
                    <option value="Doble">Doble</option>
                    <option value="Triple">Triple</option>
                    <option value="Suite">Suite</option>
                    <option value="Familiar">Familiar</option>
                </select>

                <label>Capacidad</label>
                <input type="number" name="capacidad" min="1" required>

                <label>Precio por noche</label>
                <input type="number" name="precio" min="1" step="0.01" required>

                <button type="submit">Agregar habitación</button>
            </form>
        </article>
    <?php endif; ?>

    <h3><?= $puedeGestionar ? 'Habitaciones registradas' : 'Opciones disponibles' ?></h3>

    <?php if (!$habitaciones): ?>
        <p>No hay habitaciones disponibles en este momento.</p>
    <?php endif; ?>

    <section class="menu-grid">
    <?php foreach ($habitaciones as $h): ?>
        <article class="menu-card">
            <h3>Habitación <?= htmlspecialchars($h['numero']) ?></h3>
            <p>Tipo: <?= htmlspecialchars($h['tipo']) ?></p>
            <p>Capacidad: <?= (int)$h['capacidad'] ?> personas</p>
            <p>Precio por noche: $<?= number_format((float)$h['precio'], 2, ',', '.') ?></p>

            <?php if ($puedeGestionar): ?>
                <p>Estado: <?= htmlspecialchars($h['estado']) ?></p>

                <form method="POST">
                    <input type="hidden" name="accion" value="estado">
                    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">

                    <label>Cambiar estado</label>
                    <select name="estado" required>
                        <?php foreach (
                            ['Disponible', 'Ocupada', 'Limpieza', 'Mantenimiento']
                            as $estado
                        ): ?>
                            <option value="<?= $estado ?>"
                                <?= $h['estado'] === $estado ? 'selected' : '' ?>>
                                <?= $estado ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit">Guardar estado</button>
                </form>
            <?php else: ?>
                <p>Consultá esta habitación para realizar una reserva.</p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </section>
</main>
</body>
</html>
