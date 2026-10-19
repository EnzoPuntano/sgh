
<?php
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/auth.php';

requiereLogin();

$rol = $_SESSION['usuario']['rol'];
$usuarioId = (int) $_SESSION['usuario']['id'];

if (!in_array($rol, ['Gerente', 'Mantenimiento'], true)) {
    http_response_code(403);
    exit('No tenés permiso para acceder a esta sección.');
}

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // El gerente registra y asigna una incidencia.
        if ($rol === 'Gerente' && isset($_POST['crear'])) {
            $habitacionId = (int) ($_POST['habitacion_id'] ?? 0);
            $encargadoId = (int) ($_POST['encargado_id'] ?? 0);
            $titulo = trim($_POST['titulo'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $prioridad = $_POST['prioridad'] ?? 'Media';

            if ($titulo === '' || $descripcion === '') {
                throw new Exception('Completá el título y la descripción.');
            }

            if (!in_array($prioridad, ['Baja', 'Media', 'Alta', 'Urgente'], true)) {
                throw new Exception('La prioridad seleccionada no es válida.');
            }

            $consulta = $pdo->prepare(
                "SELECT id FROM habitaciones WHERE id = ?"
            );
            $consulta->execute([$habitacionId]);

            if (!$consulta->fetch()) {
                throw new Exception('Seleccioná una habitación válida.');
            }

            $consulta = $pdo->prepare(
                "SELECT id FROM usuarios
                 WHERE id = ? AND rol_id = 5 AND activo = 1"
            );
            $consulta->execute([$encargadoId]);

            if (!$consulta->fetch()) {
                throw new Exception('Seleccioná un encargado de mantenimiento válido.');
            }

            $consulta = $pdo->prepare(
                "INSERT INTO incidencias_mantenimiento
                (habitacion_id, reportado_por, encargado_id, titulo,
                 descripcion, prioridad, estado, fecha_reporte)
                VALUES (?, ?, ?, ?, ?, ?, 'Pendiente', NOW())"
            );

            $consulta->execute([
                $habitacionId,
                $usuarioId,
                $encargadoId,
                $titulo,
                $descripcion,
                $prioridad
            ]);

            $mensaje = 'Incidencia registrada y asignada correctamente.';
        }

        // El encargado trabaja en sus incidencias asignadas.
        if (isset($_POST['actualizar'])) {
            $incidenciaId = (int) ($_POST['incidencia_id'] ?? 0);
            $nuevoEstado = $_POST['estado'] ?? '';
            $solucion = trim($_POST['solucion'] ?? '');

            if (!in_array($nuevoEstado, ['En proceso', 'Resuelta'], true)) {
                throw new Exception('El estado seleccionado no es válido.');
            }

            $pdo->beginTransaction();

            $consulta = $pdo->prepare(
                "SELECT * FROM incidencias_mantenimiento
                 WHERE id = ? FOR UPDATE"
            );
            $consulta->execute([$incidenciaId]);
            $incidencia = $consulta->fetch(PDO::FETCH_ASSOC);

            if (!$incidencia) {
                throw new Exception('No se encontró la incidencia.');
            }

            if ($rol === 'Mantenimiento' &&
                (int) $incidencia['encargado_id'] !== $usuarioId) {
                throw new Exception('Esta incidencia no te fue asignada.');
            }

            if (in_array($incidencia['estado'], ['Resuelta', 'Cancelada'], true)) {
                throw new Exception('Esta incidencia ya está cerrada.');
            }

            if ($nuevoEstado === 'Resuelta' && $solucion === '') {
                throw new Exception('Indicá qué reparación se realizó.');
            }

            if ($nuevoEstado === 'Resuelta' &&
                $incidencia['estado'] !== 'En proceso') {
                throw new Exception('Primero tenés que iniciar la reparación.');
            }

            $consulta = $pdo->prepare(
                "UPDATE incidencias_mantenimiento
                 SET estado = ?,
                     solucion = CASE WHEN ? = 'Resuelta' THEN ? ELSE solucion END,
                     fecha_resolucion = CASE WHEN ? = 'Resuelta' THEN NOW() ELSE NULL END
                 WHERE id = ?"
            );

            $consulta->execute([
                $nuevoEstado,
                $nuevoEstado,
                $solucion,
                $nuevoEstado,
                $incidenciaId
            ]);

            // Si la habitación estaba en mantenimiento, vuelve a estar disponible.
            if ($nuevoEstado === 'Resuelta') {
                $consulta = $pdo->prepare(
                    "UPDATE habitaciones
                     SET estado = 'Disponible'
                     WHERE id = ? AND estado = 'Mantenimiento'"
                );
                $consulta->execute([$incidencia['habitacion_id']]);
            }

            $pdo->commit();
            $mensaje = 'Incidencia actualizada correctamente.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e instanceof PDOException
            ? 'Ocurrió un error al guardar los datos.'
            : $e->getMessage();
    }
}

$habitaciones = $pdo->query(
    "SELECT id, numero FROM habitaciones ORDER BY numero"
)->fetchAll(PDO::FETCH_ASSOC);

$encargados = $pdo->query(
    "SELECT id, nombre, apellido FROM usuarios
     WHERE rol_id = 5 AND activo = 1
     ORDER BY apellido, nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT i.*, h.numero,
               r.nombre AS reportador_nombre,
               r.apellido AS reportador_apellido,
               e.nombre AS encargado_nombre,
               e.apellido AS encargado_apellido
        FROM incidencias_mantenimiento i
        JOIN habitaciones h ON h.id = i.habitacion_id
        JOIN usuarios r ON r.id = i.reportado_por
        JOIN usuarios e ON e.id = i.encargado_id";

if ($rol === 'Mantenimiento') {
    $sql .= " WHERE i.encargado_id = " . $usuarioId;
}

$sql .= " ORDER BY i.fecha_reporte DESC";

$incidencias = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mantenimiento - SGH</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<div class="dashboard">
    <div class="topbar">
        <h1>Gestión de mantenimiento</h1>
        <a href="index.php">Volver al inicio</a>
    </div>

    <?php if ($mensaje): ?>
        <p><?= htmlspecialchars($mensaje) ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($rol === 'Gerente'): ?>
        <h2>Registrar incidencia</h2>

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

            <label>Encargado</label>
            <select name="encargado_id" required>
                <option value="">Seleccionar encargado</option>
                <?php foreach ($encargados as $e): ?>
                    <option value="<?= $e['id'] ?>">
                        <?= htmlspecialchars($e['apellido'] . ', ' . $e['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Título</label>
            <input type="text" name="titulo" required
                   placeholder="Ej.: Aire acondicionado averiado">

            <label>Descripción</label>
            <textarea name="descripcion" required
                      placeholder="Describí el problema"></textarea>

            <label>Prioridad</label>
            <select name="prioridad" required>
                <option value="Baja">Baja</option>
                <option value="Media" selected>Media</option>
                <option value="Alta">Alta</option>
                <option value="Urgente">Urgente</option>
            </select>

            <button type="submit" name="crear">Registrar incidencia</button>
        </form>
    <?php endif; ?>

    <h2>Incidencias registradas</h2>

    <?php if (!$incidencias): ?>
        <p>No hay incidencias registradas.</p>
    <?php endif; ?>

    <?php foreach ($incidencias as $i): ?>
        <div class="menu-card">
            <h3><?= htmlspecialchars($i['titulo']) ?></h3>

            <p>Habitación: <?= htmlspecialchars($i['numero']) ?></p>
            <p>Descripción: <?= htmlspecialchars($i['descripcion']) ?></p>
            <p>Prioridad: <?= htmlspecialchars($i['prioridad']) ?></p>
            <p>Estado: <?= htmlspecialchars($i['estado']) ?></p>
            <p>Encargado:
                <?= htmlspecialchars($i['encargado_apellido'] . ', ' . $i['encargado_nombre']) ?>
            </p>

            <?php if (!empty($i['solucion'])): ?>
                <p>Solución: <?= htmlspecialchars($i['solucion']) ?></p>
            <?php endif; ?>

            <?php if (!in_array($i['estado'], ['Resuelta', 'Cancelada'], true)): ?>
                <form method="POST">
                    <input type="hidden" name="incidencia_id" value="<?= $i['id'] ?>">

                    <label>Actualizar estado</label>
                    <select name="estado" required>
                        <?php if ($i['estado'] === 'Pendiente'): ?>
                            <option value="En proceso">Iniciar reparación</option>
                        <?php else: ?>
                            <option value="Resuelta">Marcar como resuelta</option>
                        <?php endif; ?>
                    </select>

                    <label>Solución realizada (obligatoria al resolver)</label>
                    <textarea name="solucion"
                              placeholder="Ej.: Se reparó el aire acondicionado"></textarea>

                    <button type="submit" name="actualizar">Guardar cambios</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
