
<?php
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/auth.php';

requiereLogin();

$rol = $_SESSION['usuario']['rol'];
$usuarioId = (int) $_SESSION['usuario']['id'];

if (!in_array($rol, ['Gerente', 'Recepcionista', 'Cliente'], true)) {
    http_response_code(403);
    exit('No tenés permiso para acceder a las reservas.');
}

$esCliente = $rol === 'Cliente';
$puedeGestionar = in_array($rol, ['Gerente', 'Recepcionista'], true);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accion = $_POST['accion'] ?? '';

        // Registrar una reserva
        if ($accion === 'crear') {
            $clienteId = $esCliente
                ? $usuarioId
                : (int) ($_POST['cliente_id'] ?? 0);

            $habitacionId = (int) ($_POST['habitacion_id'] ?? 0);
            $entrada = $_POST['fecha_entrada'] ?? '';
            $salida = $_POST['fecha_salida'] ?? '';
            $personas = (int) ($_POST['cantidad_personas'] ?? 0);

            $fechaEntrada = DateTime::createFromFormat('!Y-m-d', $entrada);
            $fechaSalida = DateTime::createFromFormat('!Y-m-d', $salida);

            if (
                !$fechaEntrada || !$fechaSalida ||
                $fechaEntrada->format('Y-m-d') !== $entrada ||
                $fechaSalida->format('Y-m-d') !== $salida ||
                $entrada < date('Y-m-d') ||
                $salida <= $entrada ||
                $personas < 1
            ) {
                throw new Exception('Revisá las fechas y la cantidad de personas.');
            }

            // Verificar que el cliente exista y esté activo
            $stmt = $pdo->prepare(
                "SELECT id FROM usuarios
                 WHERE id = ? AND rol_id = 3 AND activo = 1"
            );
            $stmt->execute([$clienteId]);

            if (!$stmt->fetch()) {
                throw new Exception('Seleccioná un cliente válido.');
            }

            $pdo->beginTransaction();

            // Verificar habitación y capacidad
            $stmt = $pdo->prepare(
                "SELECT id, capacidad FROM habitaciones
                 WHERE id = ? AND estado = 'Disponible'
                 FOR UPDATE"
            );
            $stmt->execute([$habitacionId]);
            $habitacion = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$habitacion || $personas > (int) $habitacion['capacidad']) {
                throw new Exception(
                    'La habitación no está disponible o no tiene capacidad suficiente.'
                );
            }

            // Comprobar que no existan reservas superpuestas
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM reservas
                 WHERE habitacion_id = ?
                 AND estado IN ('Pendiente', 'Confirmada', 'En curso')
                 AND fecha_entrada < ?
                 AND fecha_salida > ?"
            );
            $stmt->execute([$habitacionId, $salida, $entrada]);

            if ((int) $stmt->fetchColumn() > 0) {
                throw new Exception(
                    'La habitación ya está reservada para esas fechas.'
                );
            }

            $stmt = $pdo->prepare(
                "INSERT INTO reservas
                 (cliente_id, habitacion_id, fecha_entrada, fecha_salida,
                  cantidad_personas, estado)
                 VALUES (?, ?, ?, ?, ?, 'Pendiente')"
            );
            $stmt->execute([
                $clienteId,
                $habitacionId,
                $entrada,
                $salida,
                $personas
            ]);

            $pdo->commit();
            header('Location: reservas.php');
            exit;
        }

        // Cambiar el estado de una reserva: solo Gerente y Recepcionista
        if ($accion === 'estado') {
            if (!$puedeGestionar) {
                throw new Exception('No tenés permiso para cambiar estados.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $estado = $_POST['estado'] ?? '';

            $estadosValidos = [
                'Pendiente',
                'Confirmada',
                'En curso',
                'Finalizada',
                'Cancelada'
            ];

            if ($id < 1 || !in_array($estado, $estadosValidos, true)) {
                throw new Exception('Los datos de la reserva no son válidos.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT habitacion_id, estado
                 FROM reservas WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$id]);
            $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reserva) {
                throw new Exception('Reserva no encontrada.');
            }

            // Transiciones permitidas entre estados
            $transiciones = [
                'Pendiente' => ['Confirmada', 'Cancelada'],
                'Confirmada' => ['En curso', 'Cancelada'],
                'En curso' => ['Finalizada'],
                'Finalizada' => [],
                'Cancelada' => []
            ];

            if (!in_array(
                $estado,
                $transiciones[$reserva['estado']] ?? [],
                true
            )) {
                throw new Exception(
                    'No se puede cambiar la reserva de ' .
                    $reserva['estado'] . ' a ' . $estado . '.'
                );
            }

            // Evitar dos estadías simultáneas en la misma habitación
            if ($estado === 'En curso') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM reservas
                     WHERE habitacion_id = ?
                     AND id <> ?
                     AND estado = 'En curso'"
                );
                $stmt->execute([$reserva['habitacion_id'], $id]);

                if ((int) $stmt->fetchColumn() > 0) {
                    throw new Exception(
                        'La habitación ya tiene una estadía en curso.'
                    );
                }

                $stmt = $pdo->prepare(
                    "UPDATE habitaciones
                     SET estado = 'Ocupada' WHERE id = ?"
                );
                $stmt->execute([$reserva['habitacion_id']]);
            }

            // Al finalizar la estadía, la habitación pasa a limpieza
            if ($estado === 'Finalizada') {
                $stmt = $pdo->prepare(
                    "UPDATE habitaciones
                     SET estado = 'Limpieza'
                     WHERE id = ?"
                );
                $stmt->execute([$reserva['habitacion_id']]);
            }

            $stmt = $pdo->prepare(
                'UPDATE reservas SET estado = ? WHERE id = ?'
            );
            $stmt->execute([$estado, $id]);

            $pdo->commit();
            header('Location: reservas.php');
            exit;
        }

        throw new Exception('Acción no válida.');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e instanceof PDOException
            ? 'No se pudo guardar la reserva. Revisá los datos.'
            : $e->getMessage();
    }
}

// Obtener reservas: el cliente solamente ve las propias
if ($esCliente) {
    $stmt = $pdo->prepare(
        'SELECT r.*, h.numero, u.nombre, u.apellido
         FROM reservas r
         JOIN habitaciones h ON h.id = r.habitacion_id
         JOIN usuarios u ON u.id = r.cliente_id
         WHERE r.cliente_id = ?
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([$usuarioId]);
    $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $reservas = $pdo->query(
        'SELECT r.*, h.numero, u.nombre, u.apellido
         FROM reservas r
         JOIN habitaciones h ON h.id = r.habitacion_id
         JOIN usuarios u ON u.id = r.cliente_id
         ORDER BY r.created_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Habitaciones disponibles para reservar
$habitaciones = $pdo->query(
    "SELECT id, numero, tipo, capacidad, precio
     FROM habitaciones
     WHERE estado = 'Disponible'
     ORDER BY numero"
)->fetchAll(PDO::FETCH_ASSOC);

// Clientes que puede seleccionar el personal
$clientes = [];

if ($puedeGestionar) {
    $clientes = $pdo->query(
        "SELECT u.id, u.nombre, u.apellido
         FROM usuarios u
         JOIN roles r ON r.id = u.rol_id
         WHERE r.nombre = 'Cliente' AND u.activo = 1
         ORDER BY u.apellido, u.nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservas | SGH</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<header class="topbar">
    <h1>SGH | Reservas</h1>
    <a href="index.php">Volver al panel</a>
</header>

<main class="dashboard">
    <h2><?= $esCliente ? 'Mis reservas' : 'Gestión de reservas' ?></h2>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <article class="menu-card">
        <h3>Nueva reserva</h3>

        <?php if (!$habitaciones): ?>
            <p>No hay habitaciones disponibles para seleccionar.</p>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="accion" value="crear">

                <?php if ($puedeGestionar): ?>
                    <label>Cliente</label>
                    <select name="cliente_id" required>
                        <option value="">Seleccionar cliente</option>
                        <?php foreach ($clientes as $c): ?>
                            <option value="<?= (int) $c['id'] ?>">
                                <?= htmlspecialchars($c['apellido'] . ', ' . $c['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <label>Habitación</label>
                <select name="habitacion_id" required>
                    <option value="">Seleccionar habitación</option>
                    <?php foreach ($habitaciones as $h): ?>
                        <option value="<?= (int) $h['id'] ?>">
                            <?= htmlspecialchars($h['numero'] . ' - ' . $h['tipo']) ?>
                            (<?= (int) $h['capacidad'] ?> personas,
                            $<?= number_format((float) $h['precio'], 2, ',', '.') ?> por noche)
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Fecha de entrada</label>
                <input type="date" name="fecha_entrada"
                       min="<?= date('Y-m-d') ?>" required>

                <label>Fecha de salida</label>
                <input type="date" name="fecha_salida"
                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>

                <label>Cantidad de personas</label>
                <input type="number" name="cantidad_personas"
                       min="1" value="1" required>

                <button type="submit">Registrar reserva</button>
            </form>
        <?php endif; ?>
    </article>

    <h3><?= $esCliente ? 'Mis reservas registradas' : 'Reservas registradas' ?></h3>

    <section class="menu-grid">
        <?php foreach ($reservas as $r): ?>
            <article class="menu-card">
                <h3>Reserva #<?= (int) $r['id'] ?></h3>
                <p>Cliente: <?= htmlspecialchars($r['apellido'] . ', ' . $r['nombre']) ?></p>
                <p>Habitación: <?= htmlspecialchars($r['numero']) ?></p>
                <p>Entrada: <?= htmlspecialchars($r['fecha_entrada']) ?></p>
                <p>Salida: <?= htmlspecialchars($r['fecha_salida']) ?></p>
                <p>Personas: <?= (int) $r['cantidad_personas'] ?></p>
                <p>Estado: <?= htmlspecialchars($r['estado']) ?></p>

                <?php if ($puedeGestionar): ?>
                    <form method="POST">
                        <input type="hidden" name="accion" value="estado">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">

                        <label>Cambiar estado</label>
                        <select name="estado" required>
                            <?php
                            $transiciones = [
                                'Pendiente' => ['Confirmada', 'Cancelada'],
                                'Confirmada' => ['En curso', 'Cancelada'],
                                'En curso' => ['Finalizada'],
                                'Finalizada' => [],
                                'Cancelada' => []
                            ];
                            ?>

                            <?php foreach ($transiciones[$r['estado']] ?? [] as $estado): ?>
                                <option value="<?= htmlspecialchars($estado) ?>">
                                    <?= htmlspecialchars($estado) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (!empty($transiciones[$r['estado']])): ?>
                            <button type="submit">Guardar estado</button>
                        <?php else: ?>
                            <p>Esta reserva ya está cerrada.</p>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if (!$reservas): ?>
            <p>Todavía no hay reservas registradas.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
