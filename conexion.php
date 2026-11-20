<?php
declare(strict_types=1);

function abrirConexion(): mysqli
{
    $host = "localhost";
    $usuario = "root";
    $clave = "";
    $base = "medicitas";

    mysqli_report(MYSQLI_REPORT_OFF);
    $conexion = new mysqli($host, $usuario, $clave, $base);

    if ($conexion->connect_error) {
        $mensaje = "No se pudo conectar con MySQL. Enciende MySQL en XAMPP e importa medicitas.sql.";
        echo "<!DOCTYPE html><html lang=\"es\"><meta charset=\"utf-8\"><title>MediCitas</title>";
        echo "<body style=\"font-family:sans-serif;padding:32px\"><h1>MediCitas</h1><p>"
            . htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") . "</p></body></html>";
        exit;
    }

    $conexion->set_charset("utf8mb4");
    return $conexion;
}
