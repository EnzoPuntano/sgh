
<?php
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/auth.php';

requiereLogin();

$rol = $_SESSION['usuario']['rol'];
$usuarioId = (int) $_SESSION['usuario']['id'];

if (!in_array($rol, ['Gerente', 'Limpieza'], true)) {
    http_response_code(403);
    exit('No tenés permiso para acceder a esta sección.');
}

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($rol === 'Gerente' && isset($_POST['crear'])) {
            $habitacionId = (int) $_POST['habitacion_id'];
            $empleadoId = (int) $_POST['empleado_id'];
            $observaciones = trim($_POST['observaciones'] ?? '');

            $consulta = $pdo->prepare(
                "SELECT id FROM habitaciones
                 WHERE id = ? AND estado = 'Limpieza'"
            );
            $consulta->execute([$habitacionId]);

            if (!$consulta->fetch()) {
                throw new Exception('La habitación debe estar marcada para limpieza.');
            }

            $consulta = $pdo->prepare(
                "SELECT id FROM usuarios
                 WHERE id = ? AND rol_id = 4 AND activo = 1"
            );
            $consulta->execute([$empleadoId]);

            if (!$consulta->fetch()) {
                throw new Exception('Seleccioná un empleado de limpieza válido.');
            }

            $consulta = $pdo->prepare(
                "INSERT INTO tareas_limpieza
                 (habitacion_id, empleado_id, fecha_asignacion, estado, observaciones)
                 VALUES (?, ?, NOW(), 'Pendiente', ?)"
            );
            $consulta->execute([$habitacionId, $empleadoId, $observaciones]);

            $mensaje = 'Tarea de limpieza asignada correctamente.';
        }

        if (isset($_POST['cambiar_estado'])) {
            $tareaId = (int) $_POST['tarea_id'];
            $nuevoEstado = $_POST['estado'];

            if (!in_array($nuevoEstado, ['En proceso', 'Finalizada'], true)) {
                throw new Exception('Estado no válido.');
            }

            $pdo->beginTransaction();

            $consulta = $pdo->prepare(
                "SELECT * FROM tareas_limpieza
                 WHERE id = ? FOR UPDATE"
            );
            $consulta->execute([$tareaId]);
            $tarea = $consulta->fetch(PDO::FETCH_ASSOC);

            if (!$tarea) {
                throw new Exception('No se encontró la tarea.');
            }

            if ($rol === 'Limpieza' && (int) $tarea['empleado_id'] !== $usuarioId) {
                throw new Exception('Esta tarea no te fue asignada.');
            }

            if ($tarea['estado'] === 'Finalizada') {
                throw new Exception('La tarea ya está finalizada.');
            }

            if ($nuevoEstado === 'Finalizada' && $tarea['estado'] !== 'En proceso') {
                throw new Exception('Primero tenés que iniciar la limpieza.');
            }

            $consulta = $pdo->prepare(
                "UPDATE tareas_limpieza
                 SET estado = ?,
                     fecha_finalizacion = CASE WHEN ? = 'Finalizada' THEN NOW() ELSE NULL END
                 WHERE id = ?"
            );
            $consulta->execute([$nuevoEstado, $nuevoEstado, $tareaId]);

            if ($nuevoEstado === 'Finalizada') {
                $consulta = $pdo->prepare(
                    "UPDATE habitaciones SET estado = 'Disponible' WHERE id = ?"
                );
                $consulta->execute([$tarea['habitacion_id']]);
            }

            $pdo->commit();
            $mensaje = 'Estado de la tarea actualizado.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof PDOException
            ? 'Ocurrió un error al guardar. Revisá los datos.'
            : $e->getMessage();
    }
}

$habitaciones = $pdo->query(
    "SELECT id, numero FROM habitaciones WHERE estado = 'Limpieza' ORDER BY numero"
)->fetchAll(PDO::FETCH_ASSOC);

$empleados = $pdo->query(
    "SELECT id, nombre, apellido FROM usuarios
     WHERE rol_id = 4 AND activo = 1 ORDER BY apellido, nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT t.*, h.numero, u.nombre, u.apellido
        FROM tareas_limpieza t
        JOIN habitaciones h ON h.id = t.habitacion_id
        JOIN usuarios u ON u.id = t.empleado_id";

if ($rol === 'Limpieza') {
    $sql .= " WHERE t.empleado_id = " . $usuarioId;
}

$sql .= " ORDER BY t.fecha_asignacion DESC";
$tareas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Limpieza - SGH</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<div class="dashboard">
    <div class="topbar">
        <h1>Gestión de limpieza</h1>
        <a href="index.php">Volver al inicio</a>
    </div>

    <?php if ($mensaje): ?>
        <p><?= htmlspecialchars($mensaje) ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($rol === 'Gerente'): ?>
        <h2>Asignar tarea</h2>

        <form method="POST">
            <label>Habitación</label>
            <select name="habitacion_id" required>
                <option value="">Seleccionar habitación</option>
                <?php foreach ($habitaciones as $h): ?>
                    <option value="<?= $h['id'] ?>">
                        Habitación <?= htmlspecialchars($h['numero']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Personal de limpieza</label>
            <select name="empleado_id" required>
                <option value="">Seleccionar empleado</option>
                <?php foreach ($empleados as $e): ?>
                    <option value="<?= $e['id'] ?>">
                        <?= htmlspecialchars($e['apellido'] . ', ' . $e['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Observaciones</label>
            <input type="text" name="observaciones" placeholder="Ej.: limpieza general">

            <button type="submit" name="crear">Asignar tarea</button>
        </form>
    <?php endif; ?>

    <h2>Tareas de limpieza</h2>

    <?php if (!$tareas): ?>
        <p>No hay tareas registradas.</p>
    <?php endif; ?>

    <?php foreach ($tareas as $t): ?>
        <div class="menu-card">
            <h3>Habitación <?= htmlspecialchars($t['numero']) ?></h3>
            <p>Empleado: <?= htmlspecialchars($t['apellido'] . ', ' . $t['nombre']) ?></p>
            <p>Estado: <?= htmlspecialchars($t['estado']) ?></p>
            <p>Observaciones: <?= htmlspecialchars($t['observaciones'] ?? '') ?></p>

            <?php if ($t['estado'] !== 'Finalizada'): ?>
                <form method="POST">
                    <input type="hidden" name="tarea_id" value="<?= $t['id'] ?>">

                    <select name="estado" required>
                        <?php if ($t['estado'] === 'Pendiente'): ?>
                            <option value="En proceso">Comenzar limpieza</option>
                        <?php else: ?>
                            <option value="Finalizada">Finalizar limpieza</option>
                        <?php endif; ?>
                    </select>

                    <button type="submit" name="cambiar_estado">Actualizar</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
