<?php
// pages/subir_foto.php
// Página obligatoria al primer ingreso: el usuario debe subir una foto de perfil.
// Una vez subida, debe_subir_foto = 0 y no se puede cambiar la foto desde aquí.
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db   = getDB();
$user = getCurrentUser();
$uid  = (int)($user['id'] ?? 0);
$rol  = $user['rol'] ?? '';
$msg  = $err = '';

// Verificar que aún deba subir foto
$stmt = $db->prepare("SELECT debe_subir_foto, foto FROM usuarios WHERE id = ?");
$stmt->execute([$uid]);
$row = $stmt->fetch();

// Si ya subió foto, redirigir al dashboard
if (!$row || $row['debe_subir_foto'] == 0) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

// ── Procesamiento del formulario ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $file = $_FILES['foto'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Debes seleccionar una foto válida para continuar.';
    } else {
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedTypes)) {
            $err = 'Solo se permiten imágenes JPG, PNG o WEBP.';
        } elseif ($file['size'] > 3 * 1024 * 1024) {
            $err = 'La imagen no debe superar 3 MB.';
        } else {
            // Crear directorio si no existe
            $uploadDir = __DIR__ . '/../uploads/fotos_usuarios/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $ext      = match($mime) {
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default      => 'jpg',
            };
            $filename = 'usr_' . $uid . '_' . time() . '.' . $ext;
            $destPath = $uploadDir . $filename;

            // Redimensionar a máximo 400×400 px usando GD
            $imgResized = redimensionarFoto($file['tmp_name'], $mime, 400);
            if ($imgResized) {
                imagejpeg($imgResized, $destPath, 88);
                imagedestroy($imgResized);
                $filename = 'usr_' . $uid . '_' . time() . '.jpg'; // siempre guardamos como jpg
                // Re-guardar como jpg correctamente
                $imgResized2 = redimensionarFoto($file['tmp_name'], $mime, 400);
                imagejpeg($imgResized2, $uploadDir . $filename, 88);
                imagedestroy($imgResized2);
            } else {
                // Fallback: mover sin redimensionar
                move_uploaded_file($file['tmp_name'], $destPath);
                $filename = 'usr_' . $uid . '_' . time() . '.' . $ext;
            }

            // Guardar en BD y marcar como foto subida
            $db->prepare("UPDATE usuarios SET foto = ?, debe_subir_foto = 0 WHERE id = ?")
               ->execute([$filename, $uid]);

            // Si es Instructor, sincronizar foto en tabla instructores
            if ($rol === 'Instructor') {
                $insStmt = $db->prepare("UPDATE instructores SET foto = ? WHERE usuario_id = ?");
                $insStmt->execute([$filename, $uid]);
            }

            // Actualizar sesión
            $_SESSION['user']['debe_subir_foto'] = 0;
            $_SESSION['user']['foto'] = $filename;

            // Redirigir al dashboard (o a cambiar contraseña si aún lo debe)
            $chk = $db->prepare("SELECT debe_cambiar_pass FROM usuarios WHERE id = ?");
            $chk->execute([$uid]);
            $debeCambiar = (bool)$chk->fetchColumn();

            if ($debeCambiar) {
                header('Location: ' . BASE_URL . '/pages/perfil.php');
            } else {
                header('Location: ' . BASE_URL . '/pages/dashboard.php');
            }
            exit;
        }
    }
}

// ── Helper: redimensionar imagen ─────────────────────────────
function redimensionarFoto(string $tmpPath, string $mime, int $maxSize) {
    $src = match($mime) {
        'image/png'  => @imagecreatefrompng($tmpPath),
        'image/webp' => @imagecreatefromwebp($tmpPath),
        default      => @imagecreatefromjpeg($tmpPath),
    };
    if (!$src) return false;

    $w = imagesx($src);
    $h = imagesy($src);

    if ($w <= $maxSize && $h <= $maxSize) {
        return $src; // No necesita redimensionar
    }

    $ratio = min($maxSize / $w, $maxSize / $h);
    $nw    = (int)round($w * $ratio);
    $nh    = (int)round($h * $ratio);

    $dst = imagecreatetruecolor($nw, $nh);
    // Fondo blanco para PNGs con transparencia
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    return $dst;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foto de Perfil — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <style>
        body { background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; font-family: 'Inter', sans-serif; }
        .foto-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 32px rgba(0,0,0,.10);
            padding: 40px 36px 32px;
            max-width: 420px;
            width: 100%;
            text-align: center;
        }
        .foto-card .logo { margin-bottom: 20px; }
        .foto-card .logo img { height: 52px; }
        .foto-card h1 { font-size: 20px; font-weight: 800; margin: 0 0 6px; color: #1a1a2e; }
        .foto-card p.sub { font-size: 13px; color: #666; margin: 0 0 24px; line-height: 1.55; }

        .preview-wrap {
            width: 130px; height: 130px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 20px;
            border: 3px solid #e0e0e0;
            background: #f5f5f5;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: border-color .2s;
            position: relative;
        }
        .preview-wrap:hover { border-color: #39a900; }
        .preview-wrap img { width: 100%; height: 100%; object-fit: cover; display: none; }
        .preview-wrap .icon-placeholder { font-size: 52px; color: #ccc; user-select: none; }
        .preview-wrap .overlay {
            position: absolute; inset: 0; background: rgba(0,0,0,.35);
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; opacity: 0; transition: opacity .2s;
            color: #fff; font-size: 13px; font-weight: 600; flex-direction: column; gap: 4px;
        }
        .preview-wrap:hover .overlay { opacity: 1; }

        input[type=file] { display: none; }

        .btn-foto {
            display: inline-block; background: #39a900; color: #fff;
            border: none; border-radius: 8px; padding: 11px 28px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            width: 100%; margin-top: 8px; transition: background .2s;
        }
        .btn-foto:hover { background: #2d8800; }
        .btn-foto:disabled { background: #aaa; cursor: not-allowed; }

        .alert-err { background: #fdecea; border-left: 4px solid #e53935; color: #b71c1c; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; text-align: left; }
        .hint { font-size: 11px; color: #aaa; margin-top: 10px; }

        .sena-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #39a900; color: #fff; border-radius: 20px;
            padding: 4px 14px; font-size: 12px; font-weight: 700;
            margin-bottom: 18px; letter-spacing: .3px;
        }
    </style>
</head>
<body>
<div class="foto-card">
    <div class="logo">
        <img src="<?= BASE_URL ?>/image/logoSena.png" alt="SENA">
    </div>
    <div class="sena-badge">📸 Primer Ingreso</div>
    <h1>Sube tu foto de perfil</h1>
    <p class="sub">
        Para que tus compañeros y el equipo puedan reconocerte fácilmente,
        necesitas subir una foto de perfil. <strong>Esta foto no podrá cambiarse</strong> una vez guardada.
    </p>

    <?php if ($err): ?>
    <div class="alert-err">⚠️ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="fotoForm">
        <?= csrfField() ?>

        <div class="preview-wrap" id="previewWrap" onclick="document.getElementById('fotoInput').click()">
            <span class="icon-placeholder" id="iconPlaceholder">👤</span>
            <img id="previewImg" alt="Vista previa">
            <div class="overlay">
                <span>📷</span>
                <span>Cambiar</span>
            </div>
        </div>

        <input type="file" id="fotoInput" name="foto" accept="image/jpeg,image/png,image/webp" onchange="previsualizarFoto(this)">

        <button type="submit" class="btn-foto" id="btnGuardar" disabled>
            Guardar foto y continuar →
        </button>
        <p class="hint">JPG, PNG o WEBP · máximo 3 MB · se recortará a 400×400 px</p>
    </form>
</div>

<script>
function previsualizarFoto(input) {
    const file = input.files[0];
    if (!file) return;

    // Validar tamaño en cliente
    if (file.size > 3 * 1024 * 1024) {
        alert('La imagen es demasiado grande. Máximo 3 MB.');
        input.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = (e) => {
        const img = document.getElementById('previewImg');
        const icon = document.getElementById('iconPlaceholder');
        img.src = e.target.result;
        img.style.display = 'block';
        icon.style.display = 'none';
        document.getElementById('btnGuardar').disabled = false;
    };
    reader.readAsDataURL(file);
}
</script>
</body>
</html>