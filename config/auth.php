<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requiereLogin(): void {
    if (!isset($_SESSION['usuario'])) {
        header('Location: /sgh/login.php');
        exit;
    }
}

function requiereRol(array $roles): void {
    requiereLogin();

    if (!in_array($_SESSION['usuario']['rol'], $roles, true)) {
        http_response_code(403);
        exit('No tenés permiso para acceder a esta sección.');
    }
}
