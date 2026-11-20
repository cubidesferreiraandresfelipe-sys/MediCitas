<?php
declare(strict_types=1);

session_start();
require __DIR__ . "/conexion.php";

/** @var mysqli $conexion */
$conexion = abrirConexion();

const CODIGO_EQUIPO = "BARZAL";

function e(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, "UTF-8");
}

function dinero(int $valor): string
{
    return "$" . number_format($valor, 0, ",", ".");
}

function token(): string
{
    if (empty($_SESSION["token"])) {
        $_SESSION["token"] = bin2hex(random_bytes(16));
    }
    return $_SESSION["token"];
}

function tokenOk(): bool
{
    return isset($_POST["token"], $_SESSION["token"])
        && hash_equals((string) $_SESSION["token"], (string) $_POST["token"]);
}

function nuevoId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . "-" . substr($hex, 8, 4) . "-" . substr($hex, 12, 4) . "-"
        . substr($hex, 16, 4) . "-" . substr($hex, 20, 12);
}

function codigoNuevo(mysqli $conexion): string
{
    $letras = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    do {
        $codigo = "MC-";
        for ($i = 0; $i < 5; $i++) {
            $codigo .= $letras[random_int(0, strlen($letras) - 1)];
        }
        $consulta = $conexion->prepare("SELECT 1 FROM appointments WHERE code = ?");
        $consulta->bind_param("s", $codigo);
        $consulta->execute();
        $existe = $consulta->get_result()->fetch_row();
        $consulta->close();
    } while ($existe);
    return $codigo;
}

function nuevoDoctorId(mysqli $conexion): string
{
    do {
        $id = "d" . substr(bin2hex(random_bytes(4)), 0, 6);
        $consulta = $conexion->prepare("SELECT 1 FROM doctors WHERE id = ?");
        $consulta->bind_param("s", $id);
        $consulta->execute();
        $existe = $consulta->get_result()->fetch_row();
        $consulta->close();
    } while ($existe);
    return $id;
}

function soloDigitos(string $valor): string
{
    return preg_replace("/\D/", "", $valor) ?? "";
}

function correoValido(string $correo): bool
{
    return (bool) preg_match("/^[^\s@]+@[^\s@]+\.[^\s@]+$/", $correo);
}

function nombreValido(string $nombre): bool
{
    return (bool) preg_match("/^[\p{L} ]{3,120}$/u", $nombre);
}

function horasDe(DateTime $fecha): array
{
    if ((int) $fecha->format("w") === 0) {
        return [];
    }
    if ((int) $fecha->format("w") === 6) {
        return ["07:00", "08:00", "09:00", "10:30"];
    }
    return ["07:00", "08:00", "09:00", "10:30", "14:00", "15:30", "16:30", "17:30"];
}

function proximosDias(int $cantidad): array
{
    $salida = [];
    $fecha = new DateTime("today");
    while (count($salida) < $cantidad) {
        if (horasDe($fecha) !== []) {
            $salida[] = clone $fecha;
        }
        $fecha->modify("+1 day");
    }
    return $salida;
}

function fechaCorta(DateTime $fecha): string
{
    $dias = ["dom", "lun", "mar", "mié", "jue", "vie", "sáb"];
    $meses = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
    $frase = $dias[(int) $fecha->format("w")] . " " . $fecha->format("j") . " " . $meses[(int) $fecha->format("n") - 1];
    return strtoupper(substr($frase, 0, 1)) . substr($frase, 1);
}

function fechaLarga(string $iso): string
{
    $dias = ["domingo", "lunes", "martes", "miércoles", "jueves", "viernes", "sábado"];
    $meses = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
    $fecha = DateTime::createFromFormat("Y-m-d", $iso);
    if (!$fecha) {
        return $iso;
    }
    $frase = $dias[(int) $fecha->format("w")] . " " . $fecha->format("j") . " de " . $meses[(int) $fecha->format("n") - 1];
    return strtoupper(substr($frase, 0, 1)) . substr($frase, 1);
}

function citaYaPaso(string $fecha, string $hora): bool
{
    $cuando = DateTime::createFromFormat("Y-m-d H:i", $fecha . " " . substr($hora, 0, 5));
    return $cuando instanceof DateTime && $cuando < new DateTime();
}

function redir(string $url): void
{
    header("Location: " . $url);
    exit;
}

$tablas = $conexion->query("SHOW TABLES LIKE 'appointments'");
$columna = $conexion->query("SHOW COLUMNS FROM users LIKE 'doctor_id'");
if ($tablas === false || $tablas->num_rows === 0 || $columna === false || $columna->num_rows === 0) {
    echo "<!DOCTYPE html><html lang=\"es\"><meta charset=\"utf-8\"><title>MediCitas</title><body style=\"font-family:sans-serif;padding:32px\"><h1>MediCitas</h1><p>Importa medicitas.sql en phpMyAdmin y vuelve a abrir esta página.</p></body></html>";
    exit;
}
$comentarioCol = $conexion->query("SHOW COLUMNS FROM appointments LIKE 'comentario'");
if ($comentarioCol && $comentarioCol->num_rows === 0) {
    $conexion->query("ALTER TABLE appointments ADD COLUMN comentario VARCHAR(500) NOT NULL DEFAULT '' AFTER motivo");
}

$usuario = null;
if (!empty($_SESSION["user_id"])) {
    $consulta = $conexion->prepare("SELECT id, name, email, cedula, celular, role, doctor_id FROM users WHERE id = ?");
    $consulta->bind_param("s", $_SESSION["user_id"]);
    $consulta->execute();
    $usuario = $consulta->get_result()->fetch_assoc() ?: null;
    $consulta->close();
    if (!$usuario) {
        unset($_SESSION["user_id"]);
    }
}

$error = "";
$ok = "";
$vista = (string) ($_GET["vista"] ?? "inicio");
if (!in_array($vista, ["inicio", "cuenta", "equipo"], true)) {
    $vista = "inicio";
}
$modo = (($_GET["modo"] ?? "") === "crear") ? "crear" : "entrar";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = (string) ($_POST["accion"] ?? "");
    if (!tokenOk()) {
        $error = "Vuelve a intentar.";
    } elseif ($accion === "salir") {
        $_SESSION = [];
        session_destroy();
        redir("index.php");
    } elseif ($accion === "registrar") {
        $vista = "cuenta";
        $modo = "crear";
        $nombre = trim((string) ($_POST["nombre"] ?? ""));
        $correo = strtolower(trim((string) ($_POST["correo"] ?? "")));
        $cedula = soloDigitos((string) ($_POST["cedula"] ?? ""));
        $celular = soloDigitos((string) ($_POST["celular"] ?? ""));
        $clave = (string) ($_POST["clave"] ?? "");
        $equipo = isset($_POST["equipo"]);
        $codigo = strtoupper(trim((string) ($_POST["codigo"] ?? "")));
        if (!nombreValido($nombre)) {
            $error = "El nombre solo puede tener letras y espacios.";
        } elseif (!correoValido($correo)) {
            $error = "Escribe un correo válido, con @ y dominio.";
        } elseif (!preg_match("/^[0-9]{6,12}$/", $cedula)) {
            $error = "La cédula solo puede tener de 6 a 12 números.";
        } elseif (!preg_match("/^3[0-9]{9}$/", $celular)) {
            $error = "El celular debe tener 10 números y empezar por 3.";
        } elseif (strlen($clave) < 8) {
            $error = "La contraseña debe tener al menos 8 caracteres.";
        } elseif ($equipo && !str_ends_with($correo, "@medicitas.com")) {
            $error = "El correo del equipo debe terminar en @medicitas.com.";
        } elseif ($equipo && !hash_equals(CODIGO_EQUIPO, $codigo)) {
            $error = "El código del equipo no coincide.";
        } else {
            $rol = $equipo ? "administrativo" : "paciente";
            $busca = $conexion->prepare("SELECT email, cedula, celular, role FROM users WHERE email = ? OR cedula = ? OR celular = ? LIMIT 1");
            $busca->bind_param("sss", $correo, $cedula, $celular);
            $busca->execute();
            $ya = $busca->get_result()->fetch_assoc();
            $busca->close();
            if ($ya && $ya["role"] === "medico") {
                $error = "Esa cuenta de médico ya existe. No se puede crear otra.";
            } elseif ($ya && strcasecmp($ya["email"], $correo) === 0) {
                $error = "Ese correo ya está registrado. Entra con tu contraseña.";
            } elseif ($ya && $ya["cedula"] === $cedula) {
                $error = "Esa cédula ya está registrada.";
            } elseif ($ya) {
                $error = "Ese celular ya está registrado.";
            } else {
                $id = nuevoId();
                $hash = password_hash($clave, PASSWORD_DEFAULT);
                $inserta = $conexion->prepare("INSERT INTO users (id, name, email, cedula, celular, pass_hash, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $inserta->bind_param("sssssss", $id, $nombre, $correo, $cedula, $celular, $hash, $rol);
                if (!$inserta->execute()) {
                    $error = "Esos datos ya pertenecen a otra cuenta.";
                } else {
                    session_regenerate_id(true);
                    $_SESSION["user_id"] = $id;
                    redir($rol === "administrativo" ? "index.php?vista=equipo" : "index.php#agenda");
                }
                $inserta->close();
            }
        }
    } elseif ($accion === "ingresar") {
        $vista = "cuenta";
        $correo = strtolower(trim((string) ($_POST["correo"] ?? "")));
        $clave = (string) ($_POST["clave"] ?? "");
        $busca = $conexion->prepare("SELECT id, pass_hash, role FROM users WHERE email = ?");
        $busca->bind_param("s", $correo);
        $busca->execute();
        $hallado = $busca->get_result()->fetch_assoc();
        $busca->close();
        if (!$hallado || !password_verify($clave, $hallado["pass_hash"])) {
            $error = "Correo o contraseña incorrectos.";
        } else {
            session_regenerate_id(true);
            $_SESSION["user_id"] = $hallado["id"];
            redir($hallado["role"] === "medico" ? "index.php#citas" : ($hallado["role"] === "administrativo" ? "index.php?vista=equipo" : "index.php#agenda"));
        }
    } elseif ($accion === "agendar") {
        if (!$usuario) {
            redir("index.php?vista=cuenta&error=" . rawurlencode("Entra para reservar."));
        }
        if ($usuario["role"] === "medico" || $usuario["role"] === "administrativo") {
            $error = "Esta cuenta no puede reservar citas.";
        } else {
        $medicoId = (string) ($_POST["med"] ?? "");
        $fecha = (string) ($_POST["fecha"] ?? "");
        $hora = (string) ($_POST["hora"] ?? "");
        $motivo = trim((string) ($_POST["motivo"] ?? ""));
        $dia = DateTime::createFromFormat("Y-m-d", $fecha);
        $busca = $conexion->prepare("SELECT id, specialty_id, name FROM doctors WHERE id = ?");
        $busca->bind_param("s", $medicoId);
        $busca->execute();
        $medico = $busca->get_result()->fetch_assoc();
        $busca->close();
        if (!$medico || !$dia || !in_array($hora, horasDe($dia), true) || strlen($motivo) > 180) {
            $error = "Ese cupo no está disponible.";
        } else {
            $id = nuevoId();
            $code = codigoNuevo($conexion);
            $esp = $medico["specialty_id"];
            $uid = $usuario["id"];
            $nombre = $usuario["name"];
            $cedula = $usuario["cedula"];
            $celular = $usuario["celular"];
            $correo = $usuario["email"];
            $inserta = $conexion->prepare(
                "INSERT INTO appointments (id, code, user_id, specialty_id, doctor_id, appt_date, appt_time, nombre, cedula, celular, correo, motivo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $inserta->bind_param("ssssssssssss", $id, $code, $uid, $esp, $medicoId, $fecha, $hora, $nombre, $cedula, $celular, $correo, $motivo);
            if (!$inserta->execute()) {
                $error = $conexion->errno === 1062 ? "Ese cupo acaba de ocuparse." : "No se pudo guardar la cita.";
            } else {
                redir("index.php?codigo=" . rawurlencode($code) . "#agenda");
            }
            $inserta->close();
        }
        }
    } elseif ($accion === "cancelar") {
        if (!$usuario) {
            redir("index.php?vista=cuenta");
        }
        $code = trim((string) ($_POST["code"] ?? ""));
        $ahora = date("Y-m-d H:i");
        if ($usuario["role"] === "administrativo") {
            $actualiza = $conexion->prepare("UPDATE appointments SET status = 'cancelada' WHERE code = ? AND status = 'confirmada' AND CONCAT(appt_date, ' ', appt_time) > ?");
            $actualiza->bind_param("ss", $code, $ahora);
            $volver = "index.php?vista=equipo";
        } elseif ($usuario["role"] === "medico") {
            $doctorId = (string) $usuario["doctor_id"];
            $actualiza = $conexion->prepare("UPDATE appointments SET status = 'cancelada' WHERE code = ? AND doctor_id = ? AND status = 'confirmada' AND CONCAT(appt_date, ' ', appt_time) > ?");
            $actualiza->bind_param("sss", $code, $doctorId, $ahora);
            $mesPost = (string) ($_POST["mes"] ?? "");
            $diaPost = (string) ($_POST["dia"] ?? "");
            $volver = "index.php#citas";
            if (preg_match("/^\d{4}-\d{2}$/", $mesPost)) {
                $volver = "index.php?mes=" . rawurlencode($mesPost);
                if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $diaPost)) {
                    $volver .= "&dia=" . rawurlencode($diaPost);
                }
                $volver .= "#citas";
            }
        } else {
            $uid = $usuario["id"];
            $actualiza = $conexion->prepare("UPDATE appointments SET status = 'cancelada' WHERE code = ? AND user_id = ? AND status = 'confirmada' AND CONCAT(appt_date, ' ', appt_time) > ?");
            $actualiza->bind_param("sss", $code, $uid, $ahora);
            $volver = "index.php#citas";
        }
        $actualiza->execute();
        $actualiza->close();
        redir($volver);
    } elseif ($accion === "comentario") {
        if (!$usuario || $usuario["role"] !== "medico") {
            redir("index.php?vista=cuenta");
        }
        $code = trim((string) ($_POST["code"] ?? ""));
        $texto = trim((string) ($_POST["comentario"] ?? ""));
        $mesPost = (string) ($_POST["mes"] ?? "");
        $diaPost = (string) ($_POST["dia"] ?? "");
        $volver = "index.php#citas";
        if (preg_match("/^\d{4}-\d{2}$/", $mesPost)) {
            $volver = "index.php?mes=" . rawurlencode($mesPost);
            if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $diaPost)) {
                $volver .= "&dia=" . rawurlencode($diaPost);
            }
            $volver .= "#citas";
        }
        $largo = function_exists("mb_strlen") ? mb_strlen($texto) : strlen($texto);
        if ($largo > 500) {
            $error = "El comentario no puede pasar de 500 caracteres.";
            $vista = "inicio";
        } else {
            $doctorId = (string) $usuario["doctor_id"];
            $hoy = date("Y-m-d");
            $busca = $conexion->prepare("SELECT 1 FROM appointments WHERE code = ? AND doctor_id = ? AND status = 'confirmada' AND appt_date <= ?");
            $busca->bind_param("sss", $code, $doctorId, $hoy);
            $busca->execute();
            $puede = $busca->get_result()->fetch_row();
            $busca->close();
            if (!$puede) {
                $error = "Solo puedes comentar tus citas confirmadas, y después de la fecha.";
                $vista = "inicio";
            } else {
                $actualiza = $conexion->prepare("UPDATE appointments SET comentario = ? WHERE code = ? AND doctor_id = ?");
                $actualiza->bind_param("sss", $texto, $code, $doctorId);
                $actualiza->execute();
                $actualiza->close();
                redir($volver);
            }
        }
    } elseif ($accion === "equipo") {
        if (!$usuario || $usuario["role"] !== "administrativo") {
            redir("index.php?vista=cuenta");
        }
        $codigo = strtoupper(trim((string) ($_POST["codigo"] ?? "")));
        if (!hash_equals(CODIGO_EQUIPO, $codigo)) {
            $error = "El código del equipo no coincide.";
            $vista = "equipo";
        } else {
            $_SESSION["equipo"] = 1;
            redir("index.php?vista=equipo");
        }
    } elseif ($accion === "crear_medico") {
        $vista = "equipo";
        if (!$usuario || $usuario["role"] !== "administrativo" || empty($_SESSION["equipo"])) {
            redir("index.php?vista=equipo");
        }
        $nombre = trim((string) ($_POST["nombre"] ?? ""));
        $correo = strtolower(trim((string) ($_POST["correo"] ?? "")));
        $cedula = soloDigitos((string) ($_POST["cedula"] ?? ""));
        $celular = soloDigitos((string) ($_POST["celular"] ?? ""));
        $clave = (string) ($_POST["clave"] ?? "");
        $espId = (string) ($_POST["especialidad"] ?? "");
        $anios = (int) ($_POST["anios"] ?? 0);
        $enfoque = trim((string) ($_POST["enfoque"] ?? ""));
        if (!nombreValido($nombre)) {
            $error = "El nombre solo puede tener letras y espacios.";
        } elseif (!correoValido($correo) || !str_ends_with($correo, "@medicitas.com")) {
            $error = "El correo del médico debe terminar en @medicitas.com.";
        } elseif (!preg_match("/^[0-9]{6,12}$/", $cedula)) {
            $error = "La cédula solo puede tener de 6 a 12 números.";
        } elseif (!preg_match("/^3[0-9]{9}$/", $celular)) {
            $error = "El celular debe tener 10 números y empezar por 3.";
        } elseif (strlen($clave) < 8) {
            $error = "La contraseña debe tener al menos 8 caracteres.";
        } elseif ($anios < 0 || $anios > 60) {
            $error = "Los años de experiencia van de 0 a 60.";
        } elseif ($enfoque !== "" && !preg_match("/^[\p{L}0-9 ,.()-]{2,120}$/u", $enfoque)) {
            $error = "El enfoque solo puede tener letras, números y espacios.";
        } else {
            $espOk = $conexion->prepare("SELECT 1 FROM specialties WHERE id = ?");
            $espOk->bind_param("s", $espId);
            $espOk->execute();
            $hayEsp = $espOk->get_result()->fetch_row();
            $espOk->close();
            if (!$hayEsp) {
                $error = "Elige una especialidad válida.";
            } else {
                $busca = $conexion->prepare("SELECT email, cedula, celular FROM users WHERE email = ? OR cedula = ? OR celular = ? LIMIT 1");
                $busca->bind_param("sss", $correo, $cedula, $celular);
                $busca->execute();
                $ya = $busca->get_result()->fetch_assoc();
                $busca->close();
                if ($ya && strcasecmp($ya["email"], $correo) === 0) {
                    $error = "Ese correo ya está registrado.";
                } elseif ($ya && $ya["cedula"] === $cedula) {
                    $error = "Esa cédula ya está registrada.";
                } elseif ($ya) {
                    $error = "Ese celular ya está registrado.";
                } else {
                    if ($enfoque === "") {
                        $enfoque = "Consulta";
                    }
                    $doctorId = nuevoDoctorId($conexion);
                    $userId = nuevoId();
                    $hash = password_hash($clave, PASSWORD_DEFAULT);
                    $rol = "medico";
                    $conexion->begin_transaction();
                    $creaDoc = $conexion->prepare("INSERT INTO doctors (id, name, specialty_id, years, focus) VALUES (?, ?, ?, ?, ?)");
                    $creaDoc->bind_param("sssis", $doctorId, $nombre, $espId, $anios, $enfoque);
                    $docOk = $creaDoc->execute();
                    $creaDoc->close();
                    $creaUser = $conexion->prepare("INSERT INTO users (id, name, email, cedula, celular, pass_hash, role, doctor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $creaUser->bind_param("ssssssss", $userId, $nombre, $correo, $cedula, $celular, $hash, $rol, $doctorId);
                    $userOk = $docOk && $creaUser->execute();
                    $creaUser->close();
                    if (!$userOk) {
                        $conexion->rollback();
                        $error = "No se pudo crear el médico. Revisa que los datos no estén repetidos.";
                    } else {
                        $conexion->commit();
                        $ok = "Médico creado. Ya puede ofrecer citas. Correo: " . $correo;
                    }
                }
            }
        }
    } elseif ($accion === "eliminar_medico") {
        $vista = "equipo";
        if (!$usuario || $usuario["role"] !== "administrativo" || empty($_SESSION["equipo"])) {
            redir("index.php?vista=equipo");
        }
        $doctorId = (string) ($_POST["doctor_id"] ?? "");
        $busca = $conexion->prepare("SELECT name FROM doctors WHERE id = ?");
        $busca->bind_param("s", $doctorId);
        $busca->execute();
        $doc = $busca->get_result()->fetch_assoc();
        $busca->close();
        if (!$doc) {
            $error = "Ese médico no existe.";
        } else {
            $conexion->begin_transaction();
            $borraCitas = $conexion->prepare("DELETE FROM appointments WHERE doctor_id = ?");
            $borraCitas->bind_param("s", $doctorId);
            $borraCitas->execute();
            $borraCitas->close();
            $borraUser = $conexion->prepare("DELETE FROM users WHERE doctor_id = ?");
            $borraUser->bind_param("s", $doctorId);
            $borraUser->execute();
            $borraUser->close();
            $borraDoc = $conexion->prepare("DELETE FROM doctors WHERE id = ?");
            $borraDoc->bind_param("s", $doctorId);
            $okBorra = $borraDoc->execute();
            $borraDoc->close();
            if (!$okBorra) {
                $conexion->rollback();
                $error = "No se pudo eliminar el médico.";
            } else {
                $conexion->commit();
                $ok = $doc["name"] . " fue eliminado. Ya no ofrece citas.";
            }
        }
    }
}

if (isset($_GET["error"]) && $error === "") {
    $error = (string) $_GET["error"];
}
if (isset($_GET["codigo"])) {
    $ok = "Cita reservada. Código " . (string) $_GET["codigo"] . ".";
}
if ($vista === "equipo" && (!$usuario || $usuario["role"] !== "administrativo")) {
    redir("index.php?vista=cuenta");
}

$especialidades = [];
$resultado = $conexion->query("SELECT id, name, blurb, price FROM specialties ORDER BY name");
while ($fila = $resultado->fetch_assoc()) {
    $especialidades[$fila["id"]] = $fila;
}
$resultado->free();

$medicos = [];
$resultado = $conexion->query("SELECT id, name, specialty_id, years, focus FROM doctors ORDER BY name");
while ($fila = $resultado->fetch_assoc()) {
    $medicos[$fila["id"]] = $fila;
}
$resultado->free();

$esp = (string) ($_GET["esp"] ?? "general");
if (!isset($especialidades[$esp])) {
    $esp = "general";
}
$med = (string) ($_GET["med"] ?? "d1");
if (!isset($medicos[$med]) || $medicos[$med]["specialty_id"] !== $esp) {
    foreach ($medicos as $item) {
        if ($item["specialty_id"] === $esp) {
            $med = $item["id"];
            break;
        }
    }
}
$fecha = (string) ($_GET["fecha"] ?? "");
$dias = proximosDias(8);
if ($fecha === "" || !DateTime::createFromFormat("Y-m-d", $fecha)) {
    $fecha = $dias[0]->format("Y-m-d");
}
$hora = (string) ($_GET["hora"] ?? "");
$diaElegido = DateTime::createFromFormat("Y-m-d", $fecha) ?: $dias[0];
$horas = horasDe($diaElegido);
if (!in_array($hora, $horas, true)) {
    $hora = $horas[0] ?? "";
}

$ocupadas = [];
$consulta = $conexion->prepare("SELECT appt_time FROM appointments WHERE doctor_id = ? AND appt_date = ? AND status = 'confirmada'");
$consulta->bind_param("ss", $med, $fecha);
$consulta->execute();
$listaOcupadas = $consulta->get_result();
while ($fila = $listaOcupadas->fetch_assoc()) {
    $ocupadas[] = substr($fila["appt_time"], 0, 5);
}
$consulta->close();
$esMedico = $usuario && $usuario["role"] === "medico";
$esAdmin = $usuario && $usuario["role"] === "administrativo";
$puedeAgendar = !$esMedico && !$esAdmin;
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>MediCitas · Citas médicas en Villavicencio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="estilos.css" />
  </head>
  <body>
    <header class="barra">
      <a class="marca" href="index.php">MediCitas</a>
      <nav class="menu" aria-label="Secciones">
        <?php if ($puedeAgendar): ?>
          <a href="index.php#especialidades">Especialidades</a>
          <a href="index.php#medicos">Médicos</a>
          <a href="index.php#agenda">Agenda</a>
        <?php endif; ?>
        <a href="index.php#citas">Mis citas</a>
      </nav>
      <div class="cuenta">
        <?php if (!$usuario): ?>
          <a class="boton solido peque" href="index.php?vista=cuenta">Ingresar</a>
        <?php else: ?>
          <span><?= e(explode(" ", $usuario["name"])[0]) ?></span>
          <?php if ($usuario["role"] === "administrativo"): ?>
            <a class="boton linea peque" href="index.php?vista=equipo">Equipo</a>
          <?php endif; ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="token" value="<?= e(token()) ?>" />
            <input type="hidden" name="accion" value="salir" />
            <button class="boton linea peque" type="submit">Salir</button>
          </form>
        <?php endif; ?>
      </div>
    </header>
    <main>
      <?php if ($error !== ""): ?><p class="aviso error"><?= e($error) ?></p><?php endif; ?>
      <?php if ($ok !== ""): ?><p class="aviso exito"><?= e($ok) ?></p><?php endif; ?>

      <?php if ($vista === "cuenta"): ?>
        <section class="pantalla"><div class="ancho estrecho">
          <a class="boton linea" href="index.php#agenda">← Volver a agendar</a>
          <h1><?= $modo === "crear" ? "Crea tu cuenta." : "Entra a tu cuenta." ?></h1>
          <p class="bajada">Los pacientes crean su cuenta aquí. Las cuentas de los médicos las crea el equipo administrativo.</p>
          <form method="post" class="tarjeta formulario">
            <input type="hidden" name="token" value="<?= e(token()) ?>" />
            <input type="hidden" name="accion" value="<?= $modo === "crear" ? "registrar" : "ingresar" ?>" />
            <?php if ($modo === "crear"): ?>
              <label>Nombre completo <input name="nombre" required maxlength="120" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñÜü ]{3,120}" /></label>
              <label>Cédula <input name="cedula" required inputmode="numeric" minlength="6" maxlength="12" pattern="[0-9]{6,12}" /></label>
              <label>Celular <input name="celular" required inputmode="numeric" minlength="10" maxlength="10" pattern="3[0-9]{9}" /></label>
            <?php endif; ?>
            <label>Correo <input name="correo" type="email" required maxlength="120" /></label>
            <label>Contraseña <input name="clave" type="password" required minlength="8" /></label>
            <?php if ($modo === "crear"): ?>
              <label class="check"><input name="equipo" type="checkbox" /> Soy del equipo administrativo</label>
              <p class="meta">El correo del equipo debe terminar en @medicitas.com.</p>
              <label>Código del consultorio <input name="codigo" autocomplete="off" /></label>
            <?php endif; ?>
            <button class="boton solido ancho-btn" type="submit"><?= $modo === "crear" ? "Crear cuenta" : "Ingresar" ?></button>
            <?php if ($modo === "entrar"): ?>
              <a class="boton texto" href="index.php?vista=cuenta&modo=crear">¿No tienes cuenta? Regístrate</a>
            <?php else: ?>
              <a class="boton texto" href="index.php?vista=cuenta">¿Ya tienes cuenta? Ingresa</a>
            <?php endif; ?>
          </form>
        </div></section>

      <?php elseif ($vista === "equipo"): ?>
        <section class="pantalla"><div class="ancho">
          <a class="boton linea" href="index.php">← Volver al inicio</a>
          <h1>Equipo</h1>
          <?php if (empty($_SESSION["equipo"])): ?>
            <form method="post" class="tarjeta formulario estrecho-form">
              <input type="hidden" name="token" value="<?= e(token()) ?>" />
              <input type="hidden" name="accion" value="equipo" />
              <p>Este apartado es solo para administrativos. Escribe el código del consultorio.</p>
              <label>Código <input name="codigo" autocomplete="off" required /></label>
              <button class="boton solido" type="submit">Entrar al panel</button>
            </form>
          <?php else: ?>
            <?php
              $todas = $conexion->query(
                  "SELECT a.code, a.nombre, a.correo, a.appt_date, a.appt_time, a.status, a.comentario, d.name AS medico
                   FROM appointments a JOIN doctors d ON d.id = a.doctor_id
                   ORDER BY a.created_at DESC"
              );
              $confirmadas = $conexion->query("SELECT COUNT(*) AS n FROM appointments WHERE status = 'confirmada'")->fetch_assoc();
            ?>
            <div class="stats"><div><span>Confirmadas</span><strong><?= (int) $confirmadas["n"] ?></strong></div></div>

            <h2>Médicos</h2>
            <p class="meta">Un médico nuevo queda disponible para que los pacientes agenden con él.</p>
            <form method="post" class="tarjeta formulario">
              <input type="hidden" name="token" value="<?= e(token()) ?>" />
              <input type="hidden" name="accion" value="crear_medico" />
              <label>Nombre completo <input name="nombre" required maxlength="120" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñÜü ]{3,120}" /></label>
              <label>Especialidad
                <select name="especialidad" required>
                  <?php foreach ($especialidades as $item): ?>
                    <option value="<?= e($item["id"]) ?>"><?= e($item["name"]) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Años de experiencia <input name="anios" type="number" min="0" max="60" value="1" required /></label>
              <label>Enfoque <input name="enfoque" maxlength="120" placeholder="Ej. Medicina familiar" /></label>
              <label>Correo <input name="correo" type="email" required maxlength="120" placeholder="nombre@medicitas.com" /></label>
              <label>Cédula <input name="cedula" required inputmode="numeric" minlength="6" maxlength="12" pattern="[0-9]{6,12}" /></label>
              <label>Celular <input name="celular" required inputmode="numeric" minlength="10" maxlength="10" pattern="3[0-9]{9}" /></label>
              <label>Contraseña <input name="clave" type="password" required minlength="8" /></label>
              <button class="boton solido" type="submit">Crear médico</button>
            </form>
            <?php
              $listaMedicos = $conexion->query(
                  "SELECT d.id, d.name, d.years, s.name AS especialidad, u.email
                   FROM doctors d
                   JOIN specialties s ON s.id = d.specialty_id
                   LEFT JOIN users u ON u.doctor_id = d.id
                   ORDER BY d.name"
              );
            ?>
            <div class="tabla-scroll">
              <table>
                <thead><tr><th>Médico</th><th>Especialidad</th><th>Correo</th><th>Años</th><th></th></tr></thead>
                <tbody>
                  <?php while ($doc = $listaMedicos->fetch_assoc()): ?>
                    <tr>
                      <td><?= e($doc["name"]) ?></td>
                      <td><?= e($doc["especialidad"]) ?></td>
                      <td><?= e((string) $doc["email"]) ?></td>
                      <td><?= (int) $doc["years"] ?></td>
                      <td>
                        <form method="post" onsubmit="return confirm('¿Eliminar este médico? Sus citas también se borran.');">
                          <input type="hidden" name="token" value="<?= e(token()) ?>" />
                          <input type="hidden" name="accion" value="eliminar_medico" />
                          <input type="hidden" name="doctor_id" value="<?= e($doc["id"]) ?>" />
                          <button class="boton linea peque" type="submit">Eliminar</button>
                        </form>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>

            <h2>Citas de la clínica</h2>
            <div class="tabla-scroll">
              <table>
                <thead><tr><th>Código</th><th>Paciente</th><th>Correo</th><th>Médico</th><th>Cuándo</th><th>Estado</th><th>Comentario del médico</th><th></th></tr></thead>
                <tbody>
                  <?php while ($cita = $todas->fetch_assoc()): ?>
                    <tr>
                      <td><?= e($cita["code"]) ?></td>
                      <td><?= e($cita["nombre"]) ?></td>
                      <td><?= e($cita["correo"]) ?></td>
                      <td><?= e($cita["medico"]) ?></td>
                      <td><?= e(fechaLarga($cita["appt_date"])) ?> <?= e(substr($cita["appt_time"], 0, 5)) ?></td>
                      <td><?= e($cita["status"]) ?></td>
                      <td class="nota"><?= $cita["comentario"] !== "" ? e($cita["comentario"]) : "—" ?></td>
                      <td>
                        <?php if ($cita["status"] === "confirmada" && !citaYaPaso($cita["appt_date"], $cita["appt_time"])): ?>
                          <form method="post">
                            <input type="hidden" name="token" value="<?= e(token()) ?>" />
                            <input type="hidden" name="accion" value="cancelar" />
                            <input type="hidden" name="code" value="<?= e($cita["code"]) ?>" />
                            <button class="boton linea peque" type="submit">Cancelar</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div></section>

      <?php else: ?>
        <div id="inicio">
          <section class="hero"><div class="ancho hero-grid">
            <?php if ($esMedico): ?>
              <div>
                <p class="sobre">Médico de la sede</p>
                <h1>Hola, <?= e($usuario["name"]) ?>.</h1>
                <p class="bajada">Aquí ves el calendario de tus citas. Elige un día para ver al paciente y cancelar si hace falta.</p>
                <div class="fila"><a class="boton solido" href="#citas">Ver mis citas</a></div>
              </div>
            <?php elseif ($esAdmin): ?>
              <div>
                <p class="sobre">Equipo administrativo</p>
                <h1>Hola, <?= e($usuario["name"]) ?>.</h1>
                <p class="bajada">Desde el panel del equipo ves las citas, creas médicos nuevos y puedes eliminarlos. Esta cuenta no reserva.</p>
                <div class="fila"><a class="boton solido" href="index.php?vista=equipo">Ir al equipo</a></div>
              </div>
            <?php else: ?>
            <div>
              <p class="sobre">Clínica particular · Barzal, Villavicencio</p>
              <h1>Tu cita médica, sin sala de espera.</h1>
              <p class="bajada">Elige especialidad, médico y hora. La cita queda a tu nombre y nadie más puede tomar ese cupo.</p>
              <div class="fila"><a class="boton solido" href="#agenda">Agendar ahora</a><a class="boton linea" href="#medicos">Ver médicos</a></div>
            </div>
            <aside class="tarjeta pasos">
              <p class="sobre">Cómo reservar</p>
              <ol>
                <li><span>1</span><div><strong>Especialidad</strong><p>Elige el tipo de consulta.</p></div></li>
                <li><span>2</span><div><strong>Médico y hora</strong><p>Toma un cupo libre.</p></div></li>
                <li><span>3</span><div><strong>Confirmar</strong><p>La cita queda a tu nombre.</p></div></li>
              </ol>
            </aside>
            <?php endif; ?>
          </div></section>

          <?php if ($puedeAgendar): ?>
          <section id="especialidades" class="bloque"><div class="ancho">
            <h2>Qué puedes agendar</h2>
            <div class="rejilla">
              <?php foreach ($especialidades as $item): ?>
                <a class="especialidad<?= $item["id"] === $esp ? " activo" : "" ?>" href="index.php?esp=<?= e($item["id"]) ?>#agenda">
                  <strong><?= e($item["name"]) ?></strong><span><?= e($item["blurb"]) ?></span><span class="precio"><?= e(dinero((int) $item["price"])) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div></section>

          <section id="medicos" class="bloque suave"><div class="ancho">
            <h2>Médicos de la sede</h2>
            <div class="rejilla">
              <?php foreach ($medicos as $item): ?>
                <?php if ($item["specialty_id"] !== $esp) continue; ?>
                <article class="medico">
                  <strong><?= e($item["name"]) ?></strong>
                  <span class="meta"><?= e($item["focus"]) ?> · <?= (int) $item["years"] ?> años</span>
                  <a class="boton linea peque" href="index.php?esp=<?= e($esp) ?>&med=<?= e($item["id"]) ?>#agenda">Ver cupos</a>
                </article>
              <?php endforeach; ?>
            </div>
          </div></section>

          <section id="agenda" class="bloque"><div class="ancho">
            <h2>Reserva tu cupo</h2>
            <p class="ayuda">Día</p>
            <div class="chips dias">
              <?php foreach ($dias as $dia): ?>
                <?php $iso = $dia->format("Y-m-d"); ?>
                <a class="chip<?= $iso === $fecha ? " activo" : "" ?>" href="index.php?esp=<?= e($esp) ?>&med=<?= e($med) ?>&fecha=<?= e($iso) ?>#agenda"><?= e(fechaCorta($dia)) ?></a>
              <?php endforeach; ?>
            </div>
            <p class="ayuda">Hora de <?= e($medicos[$med]["name"] ?? "") ?></p>
            <div class="chips">
              <?php foreach ($horas as $item): ?>
                <?php $tomada = in_array($item, $ocupadas, true); ?>
                <?php if ($tomada): ?>
                  <span class="chip">Ocupada <?= e($item) ?></span>
                <?php else: ?>
                  <a class="chip<?= $item === $hora ? " activo" : "" ?>" href="index.php?esp=<?= e($esp) ?>&med=<?= e($med) ?>&fecha=<?= e($fecha) ?>&hora=<?= e($item) ?>#agenda"><?= e($item) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <form method="post" class="tarjeta formulario">
              <h3>Datos del paciente</h3>
              <input type="hidden" name="token" value="<?= e(token()) ?>" />
              <input type="hidden" name="accion" value="agendar" />
              <input type="hidden" name="med" value="<?= e($med) ?>" />
              <input type="hidden" name="fecha" value="<?= e($fecha) ?>" />
              <input type="hidden" name="hora" value="<?= e($hora) ?>" />
              <?php if ($usuario && $usuario["role"] === "medico"): ?>
                <p>Esta cuenta es de médico. Tus citas están en <a href="#citas">Mis citas</a>.</p>
              <?php elseif ($usuario): ?>
                <p><?= e($usuario["name"]) ?> · <?= e($usuario["cedula"]) ?> · <?= e($usuario["celular"]) ?> · <?= e($usuario["email"]) ?></p>
                <label>Motivo (opcional) <input name="motivo" maxlength="180" /></label>
                <button class="boton solido ancho-btn" type="submit"<?= in_array($hora, $ocupadas, true) ? " disabled" : "" ?>>Confirmar cita</button>
              <?php else: ?>
                <a class="boton solido ancho-btn" href="index.php?vista=cuenta">Ingresar para confirmar</a>
              <?php endif; ?>
            </form>
          </div></section>
          <?php endif; ?>

          <section id="citas" class="bloque suave"><div class="ancho">
            <h2><?= $esMedico ? "Calendario" : "Mis citas" ?></h2>
            <?php if (!$usuario): ?>
              <p class="vacio">Entra para ver tus citas. <a href="index.php?vista=cuenta">Ingresar</a></p>
            <?php elseif ($usuario["role"] === "medico"): ?>
              <?php
                $doctorId = (string) $usuario["doctor_id"];
                $mes = (string) ($_GET["mes"] ?? date("Y-m"));
                $inicioMes = DateTime::createFromFormat("Y-m-d", $mes . "-01");
                if (!$inicioMes || $inicioMes->format("Y-m") !== $mes) {
                    $inicioMes = new DateTime("first day of this month");
                    $mes = $inicioMes->format("Y-m");
                }
                $finMes = (clone $inicioMes)->modify("last day of this month");
                $diaSel = (string) ($_GET["dia"] ?? "");
                if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $diaSel) || !str_starts_with($diaSel, $mes . "-")) {
                    $diaSel = "";
                }
                $anterior = (clone $inicioMes)->modify("-1 month")->format("Y-m");
                $siguiente = (clone $inicioMes)->modify("+1 month")->format("Y-m");
                $nombresMes = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
                $tituloMes = ucfirst($nombresMes[(int) $inicioMes->format("n") - 1]) . " " . $inicioMes->format("Y");
                $porDia = [];
                $mias = $conexion->prepare("SELECT code, nombre, celular, motivo, comentario, appt_date, appt_time, status FROM appointments WHERE doctor_id = ? AND appt_date BETWEEN ? AND ? ORDER BY appt_time");
                $desde = $inicioMes->format("Y-m-d");
                $hasta = $finMes->format("Y-m-d");
                $mias->bind_param("sss", $doctorId, $desde, $hasta);
                $mias->execute();
                $filas = $mias->get_result();
                while ($fila = $filas->fetch_assoc()) {
                    $porDia[$fila["appt_date"]][] = $fila;
                }
                $mias->close();
                $hoy = date("Y-m-d");
              ?>
              <div class="cal-nav">
                <a class="boton linea peque" href="index.php?mes=<?= e($anterior) ?>#citas">←</a>
                <h3><?= e($tituloMes) ?></h3>
                <a class="boton linea peque" href="index.php?mes=<?= e($siguiente) ?>#citas">→</a>
              </div>
              <div class="cal" aria-label="Calendario de citas">
                <?php foreach (["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"] as $nombreDia): ?>
                  <div class="cal-nombre"><?= $nombreDia ?></div>
                <?php endforeach; ?>
                <?php for ($hueco = 1; $hueco < (int) $inicioMes->format("N"); $hueco++): ?>
                  <div class="cal-vacio"></div>
                <?php endfor; ?>
                <?php for ($numero = 1; $numero <= (int) $inicioMes->format("t"); $numero++): ?>
                  <?php
                    $iso = $inicioMes->format("Y-m-") . str_pad((string) $numero, 2, "0", STR_PAD_LEFT);
                    $delDia = $porDia[$iso] ?? [];
                    $confirmadasDia = 0;
                    foreach ($delDia as $citaDia) {
                        if ($citaDia["status"] === "confirmada") {
                            $confirmadasDia++;
                        }
                    }
                    $clases = "cal-dia";
                    if ($iso === $diaSel) {
                        $clases .= " activo";
                    } elseif ($confirmadasDia > 0) {
                        $clases .= " tiene";
                    }
                    if ($iso === $hoy) {
                        $clases .= " hoy";
                    }
                  ?>
                  <a class="<?= e($clases) ?>" href="index.php?mes=<?= e($mes) ?>&dia=<?= e($iso) ?>#citas">
                    <span class="n"><?= $numero ?></span>
                    <?php if ($confirmadasDia > 0): ?><span class="cant"><?= $confirmadasDia ?> cita<?= $confirmadasDia === 1 ? "" : "s" ?></span><?php endif; ?>
                  </a>
                <?php endfor; ?>
              </div>
              <?php if ($diaSel === ""): ?>
                <p class="vacio cal-ayuda">Elige un día marcado para ver las citas.</p>
              <?php else: ?>
                <h3 class="cal-ayuda"><?= e(fechaLarga($diaSel)) ?></h3>
                <?php if (!isset($porDia[$diaSel])): ?>
                  <p class="vacio">No hay citas este día.</p>
                <?php else: ?>
                  <?php foreach ($porDia[$diaSel] as $cita): ?>
                    <?php $yaPaso = $cita["appt_date"] <= date("Y-m-d"); ?>
                    <article class="cita">
                      <div>
                        <strong><?= e($cita["code"]) ?></strong> · <?= e($cita["status"]) ?><br />
                        <?= e($cita["nombre"]) ?> · <?= e($cita["celular"]) ?><?= $cita["motivo"] !== "" ? " · " . e($cita["motivo"]) : "" ?><br />
                        <?= e(substr($cita["appt_time"], 0, 5)) ?>
                        <?php if ($cita["comentario"] !== ""): ?>
                          <p class="comentario"><strong>Comentario:</strong> <?= e($cita["comentario"]) ?></p>
                        <?php endif; ?>
                        <?php if ($cita["status"] === "confirmada" && $yaPaso): ?>
                          <form method="post" class="formulario comentario-form">
                            <input type="hidden" name="token" value="<?= e(token()) ?>" />
                            <input type="hidden" name="accion" value="comentario" />
                            <input type="hidden" name="code" value="<?= e($cita["code"]) ?>" />
                            <input type="hidden" name="mes" value="<?= e($mes) ?>" />
                            <input type="hidden" name="dia" value="<?= e($diaSel) ?>" />
                            <label>Qué tenía el paciente
                              <textarea name="comentario" maxlength="500" rows="3" placeholder="Escribe el comentario de la consulta"><?= e($cita["comentario"]) ?></textarea>
                            </label>
                            <button class="boton solido peque" type="submit">Guardar comentario</button>
                          </form>
                        <?php elseif ($cita["status"] === "confirmada"): ?>
                          <p class="vacio">El comentario se puede dejar después de la cita.</p>
                        <?php endif; ?>
                      </div>
                      <?php if ($cita["status"] === "confirmada" && !citaYaPaso($cita["appt_date"], $cita["appt_time"])): ?>
                        <form method="post">
                          <input type="hidden" name="token" value="<?= e(token()) ?>" />
                          <input type="hidden" name="accion" value="cancelar" />
                          <input type="hidden" name="code" value="<?= e($cita["code"]) ?>" />
                          <input type="hidden" name="mes" value="<?= e($mes) ?>" />
                          <input type="hidden" name="dia" value="<?= e($diaSel) ?>" />
                          <button class="boton linea peque" type="submit">Cancelar</button>
                        </form>
                      <?php endif; ?>
                    </article>
                  <?php endforeach; ?>
                <?php endif; ?>
              <?php endif; ?>
              <?php
                $historial = $conexion->prepare("SELECT code, nombre, appt_date, appt_time, status, comentario FROM appointments WHERE doctor_id = ? AND appt_date <= CURDATE() ORDER BY appt_date DESC, appt_time DESC");
                $historial->bind_param("s", $doctorId);
                $historial->execute();
                $filasHist = $historial->get_result();
              ?>
              <h3 class="cal-ayuda">Historial de citas</h3>
              <?php if ($filasHist->num_rows === 0): ?>
                <p class="vacio">Todavía no hay citas pasadas.</p>
              <?php else: ?>
                <?php while ($cita = $filasHist->fetch_assoc()): ?>
                  <article class="cita">
                    <div>
                      <strong><?= e($cita["code"]) ?></strong> · <?= e($cita["status"]) ?><br />
                      <?= e($cita["nombre"]) ?> · <?= e(fechaLarga($cita["appt_date"])) ?> · <?= e(substr($cita["appt_time"], 0, 5)) ?>
                      <p class="comentario"><?= $cita["comentario"] !== "" ? e($cita["comentario"]) : "Sin comentario." ?></p>
                    </div>
                  </article>
                <?php endwhile; ?>
              <?php endif; $historial->close(); ?>
            <?php else: ?>
              <?php
                $uid = $usuario["id"];
                $mias = $conexion->prepare("SELECT a.code, a.appt_date, a.appt_time, a.status, d.name AS medico FROM appointments a JOIN doctors d ON d.id = a.doctor_id WHERE a.user_id = ? ORDER BY a.appt_date DESC");
                $mias->bind_param("s", $uid);
                $mias->execute();
                $filas = $mias->get_result();
              ?>
              <?php if ($filas->num_rows === 0): ?><p class="vacio">Todavía no tienes citas.</p><?php endif; ?>
              <?php while ($cita = $filas->fetch_assoc()): ?>
                <article class="cita">
                  <div><strong><?= e($cita["code"]) ?></strong> · <?= e($cita["status"]) ?><br /><?= e($cita["medico"]) ?> · <?= e(fechaLarga($cita["appt_date"])) ?> · <?= e(substr($cita["appt_time"], 0, 5)) ?></div>
                  <?php if ($cita["status"] === "confirmada" && !citaYaPaso($cita["appt_date"], $cita["appt_time"])): ?>
                    <form method="post">
                      <input type="hidden" name="token" value="<?= e(token()) ?>" />
                      <input type="hidden" name="accion" value="cancelar" />
                      <input type="hidden" name="code" value="<?= e($cita["code"]) ?>" />
                      <button class="boton linea peque" type="submit">Cancelar</button>
                    </form>
                  <?php endif; ?>
                </article>
              <?php endwhile; $mias->close(); ?>
            <?php endif; ?>
          </div></section>
          <footer class="pie"><div class="ancho"><h2>Barzal, Villavicencio</h2><p>Carrera 32 con calle 37. Lunes a sábado.</p><p><a href="tel:+576086714400">(608) 671 4400</a></p></div></footer>
        </div>
      <?php endif; ?>
    </main>
  </body>
</html>
