<?php
// convertir.php
declare(strict_types=1);

/*
 * Conversor DOC/DOCX/ODT/RTF/TXT → PDF con LibreOffice headless
 * - Formulario elegante (CSS puro)
 * - Subida segura a tmp
 * - Perfil aislado de LibreOffice (evita dconf/HOME → error 77)
 * - Doble intento de conversión (writer_pdf_Export → genérico)
 * - Normaliza nombres a ASCII para evitar problemas de locale/ruta
 * - Usa URI file:// para el archivo fuente
 * - Detecta LibreOffice SNAP y aborta con mensaje claro
 */

// -------------------- CONFIG --------------------
const MAX_UPLOAD_MB = 50;

// Fuerza la ruta del binario APT (no SNAP)
const SOFFICE_PATH = '/usr/bin/soffice';

// Carpeta base temporal (null = sys_get_temp_dir())
// Si prefieres una carpeta fija, descomenta y ajusta:
// const BASE_TMP_DIR = '/var/lib/lo_work';
const BASE_TMP_DIR = null;

// (Opcional) Perfil persistente (null = efímero). Si lo activas, crea y da permisos a www-data.
// const PERSISTENT_PROFILE_PATH = '/var/lib/lo_profile';
const PERSISTENT_PROFILE_PATH = null;

const ALLOWED_EXT = ['doc','docx','odt','rtf','txt'];
const ALLOWED_MIME = [
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.oasis.opendocument.text',
    'application/rtf','text/rtf',
    'text/plain'
];
// ------------------------------------------------

function render_page(string $message = '', string $type = 'info'): void {
    $cssType = [
        'info'    => '#2b6cb0',
        'success' => '#2f855a',
        'error'   => '#c53030',
        'warn'    => '#b7791f'
    ][$type] ?? '#2b6cb0';

    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Conversor a PDF</title>';
    echo '<style>
        :root{ --ink:#e5e7eb; --muted:#9ca3af; --accent:#22d3ee; --accent2:#34d399; }
        *{box-sizing:border-box}
        body{
          margin:0; padding:24px; min-height:100vh; display:flex; align-items:center; justify-content:center;
          background:linear-gradient(135deg,#0f172a 0%,#111827 100%); color:var(--ink);
          font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
        }
        .wrap{width:100%; max-width:720px}
        .card{
          background:linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
          border:1px solid rgba(255,255,255,0.08); border-radius:16px;
          padding:28px; box-shadow:0 20px 60px rgba(0,0,0,.35); backdrop-filter: blur(6px);
        }
        .title{margin:0 0 10px; font-weight:800; font-size:clamp(24px,3.2vw,30px)}
        .subtitle{margin:0 0 24px; color:var(--muted)}
        .drop{
          border:2px dashed rgba(255,255,255,.15); border-radius:14px; padding:22px; text-align:center;
          transition:.2s; margin-bottom:8px;
        }
        .drop:hover{border-color:var(--accent); background: rgba(34,211,238,.06)}
        .file-row{display:flex; gap:12px; align-items:center; justify-content:center; flex-wrap:wrap; margin-top:10px}
        input[type=file]{display:inline-block; max-width:100%; color:var(--ink)}
        .btn{
          appearance:none; border:0; border-radius:12px; padding:12px 18px; font-size:16px; font-weight:700;
          background:linear-gradient(135deg,var(--accent),var(--accent2)); color:#0b1221; cursor:pointer;
          transition:.15s transform,.15s box-shadow; box-shadow:0 8px 24px rgba(34,211,238,.25);
        }
        .btn:hover{transform:translateY(-1px); box-shadow:0 12px 30px rgba(52,211,153,.3)}
        .note{font-size:14px; color:var(--muted); margin-top:8px}
        .alert{
          margin:0 0 18px; padding:12px 14px; border-left:4px solid '.$cssType.';
          background: rgba(255,255,255,.05); border-radius:10px; font-size:14px; color:#e8eaed; white-space:pre-wrap;
        }
        .footer{margin-top:18px; font-size:12px; color:#94a3b8; text-align:center}
         a{text-decoration:none; color:white}
    </style>';
    echo '</head><body><div class="wrap"><div class="card">';
    echo '<a href="../pdf.html" onclick="history.back()">&larr;&nbsp;Volver&nbsp;</a>';
    echo '<h1 class="title">Conversor a PDF</h1>';
    echo '<p class="subtitle">Sube un documento <strong>DOC, DOCX, ODT, RTF o TXT</strong> y se convertirá a PDF manteniendo el nombre base.</p>';

    if ($message !== '') echo '<div class="alert">'.$message.'</div>';

    echo '<form class="drop" method="post" enctype="multipart/form-data" novalidate>
            <div>Arrastra tu archivo aquí o selecciónalo</div>
            <div class="file-row">
                <input type="file" name="archivo" accept=".doc,.docx,.odt,.rtf,.txt" required>
                <button class="btn" type="submit">Convertir a PDF</button>
            </div>
            <div class="note">Tamaño máximo: '.MAX_UPLOAD_MB.' MB. La conversión se realiza localmente con LibreOffice.</div>
          </form>
          <div class="footer">Privado: los archivos temporales se eliminan tras la conversión.</div>';
    echo '</div></div></body></html>';
    exit;
}

function run_cmd(array $cmd, ?string $cwd = null, int $timeoutSec = 240, array $env = []): array {
    $desc = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $process = proc_open($cmd, $desc, $pipes, $cwd ?? null, $env ?: null);
    if (!\is_resource($process)) return [1, '', 'No se pudo iniciar el proceso.'];

    $start = time(); $stdout=''; $stderr='';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        if ((time() - $start) > $timeoutSec) { proc_terminate($process, 9); break; }
        usleep(100_000);
        $status = proc_get_status($process);
    } while ($status['running']);

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    foreach ($pipes as $p) if (is_resource($p)) fclose($p);
    $exit = proc_close($process);
    return [$exit, $stdout, $stderr];
}

function find_soffice(?string $custom): ?string {
    if ($custom && is_file($custom) && is_executable($custom)) return $custom;
    $candidates = [$custom, '/usr/bin/soffice','/usr/local/bin/soffice','/bin/soffice','soffice'];
    foreach ($candidates as $c) {
        if (!$c) continue;
        if (strpos($c, '/snap/bin/') === 0) continue; // evita SNAP
        $out=[]; @exec('command -v '.escapeshellarg($c).' 2>/dev/null', $out, $rc);
        if ($rc===0 && !empty($out)) {
            $path = trim($out[0]);
            if (strpos($path, '/snap/bin/') === 0) continue; // evita SNAP
            return $path;
        }
        if (@is_executable($c) && strpos($c, '/snap/bin/') !== 0) return $c;
    }
    return null;
}

function sanitize_filename(string $name): string {
    $name = preg_replace('/[^\p{L}\p{N}\.\-_ ]/u', '_', $name) ?: 'documento';
    return ltrim($name, '.') ?: 'documento';
}

function transliterate_ascii(string $s): string {
    $t = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    if ($t === false) $t = preg_replace('/[^\x20-\x7E]/', '_', $s);
    $t = preg_replace('/[^\w\.\- ]/', '_', $t);
    $t = preg_replace('/_+/', '_', $t);
    return trim($t) ?: 'input';
}

function file_uri_from_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $parts = array_map('rawurlencode', explode('/', $path));
    return 'file:///' . ltrim(implode('/', $parts), '/');
}

function base_tmp_dir(): string {
    if (BASE_TMP_DIR) {
        if (!is_dir(BASE_TMP_DIR)) @mkdir(BASE_TMP_DIR, 0700, true);
        return rtrim(BASE_TMP_DIR, DIRECTORY_SEPARATOR);
    }
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
}

// ---------- GET ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') render_page();

// ---------- POST ----------
try {
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se recibió un archivo válido.');
    }
    if ($_FILES['archivo']['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
        throw new RuntimeException('El archivo supera el límite de '.MAX_UPLOAD_MB.' MB.');
    }

    $origName = sanitize_filename($_FILES['archivo']['name'] ?? 'documento');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) {
        throw new RuntimeException('Extensión no permitida. Solo: '.implode(', ', ALLOWED_EXT).'.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES['archivo']['tmp_name']);
    if ($mime === false || !in_array($mime, ALLOWED_MIME, true)) {
        if (!in_array($ext, ['txt','rtf'], true)) {
            throw new RuntimeException('Tipo MIME no reconocido o no permitido ('.$mime.').');
        }
    }

    $baseTmp = base_tmp_dir();
    $work = $baseTmp . DIRECTORY_SEPARATOR . 'convpdf_' . bin2hex(random_bytes(8));
    if (!mkdir($work, 0700, true)) throw new RuntimeException('No se pudo crear el directorio temporal.');

    // Mover con nombre original y luego normalizar a ASCII para LO
    $uploadedOrig = $work . DIRECTORY_SEPARATOR . $origName;
    if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $uploadedOrig)) {
        throw new RuntimeException('No se pudo mover el archivo subido.');
    }

    $safeBase = transliterate_ascii(pathinfo($origName, PATHINFO_FILENAME));
    $safeName = $safeBase . '.' . $ext;
    $uploadedPath = $work . DIRECTORY_SEPARATOR . $safeName;
    if ($uploadedPath !== $uploadedOrig) {
        if (!@rename($uploadedOrig, $uploadedPath)) {
            if (!@copy($uploadedOrig, $uploadedPath)) {
                throw new RuntimeException('No se pudo normalizar el nombre del archivo.');
            }
            @unlink($uploadedOrig);
        }
    }
    @chmod($uploadedPath, 0644);

    $soffice = find_soffice(SOFFICE_PATH);
    if ($soffice === null) {
        throw new RuntimeException('LibreOffice no encontrado. Asegura que existe /usr/bin/soffice (APT) y no SNAP.');
    }
    if (strpos($soffice, '/snap/bin/') === 0) {
        throw new RuntimeException('Detectado LibreOffice SNAP ('.$soffice.'). Instala la versión APT y usa /usr/bin/soffice.');
    }

    // Versión (sanity check)
    [$vCode,$vOut,$vErr] = run_cmd([$soffice,'--version']);
    if ($vCode !== 0) {
        throw new RuntimeException("No se pudo ejecutar LibreOffice (--version).\nSTDERR:\n$vErr\nSTDOUT:\n$vOut");
    }

    // Perfil de LibreOffice
    if (PERSISTENT_PROFILE_PATH) {
        $profile = rtrim(PERSISTENT_PROFILE_PATH, DIRECTORY_SEPARATOR);
        if (!is_dir($profile) && !@mkdir($profile, 0700, true)) {
            $profile = $work . DIRECTORY_SEPARATOR . 'lo_profile';
            if (!mkdir($profile, 0700, true)) throw new RuntimeException('No se pudo crear el perfil de LibreOffice.');
        }
    } else {
        $profile = $work . DIRECTORY_SEPARATOR . 'lo_profile';
        if (!mkdir($profile, 0700, true)) throw new RuntimeException('No se pudo crear el perfil de LibreOffice.');
    }

    $xdgConfig = $profile . '/.config';
    $xdgCache  = $profile . '/.cache';
    $xdgRun    = $profile . '/.run';
    @mkdir($xdgConfig, 0700, true);
    @mkdir($xdgCache,  0700, true);
    @mkdir($xdgRun,    0700, true);

    // Entorno reforzado UTF-8 + headless
    $env = [
        'HOME'             => $profile,
        'TMPDIR'           => $work,
        'XDG_CONFIG_HOME'  => $xdgConfig,
        'XDG_CACHE_HOME'   => $xdgCache,
        'XDG_RUNTIME_DIR'  => $xdgRun,
        'LANG'             => 'C.UTF-8',
        'LC_ALL'           => 'C.UTF-8',
        'LANGUAGE'         => 'es_ES:es',
        'SAL_USE_VCLPLUGIN'=> 'headless',
    ];
    $userInstallation = file_uri_from_path($profile);

    @set_time_limit(420);

    // INTENTO 1: filtro explícito (fuente como URI file://)
    $srcUri = file_uri_from_path($uploadedPath);
    $cmd1 = [
        $soffice,'--headless','--nologo','--nodefault','--nolockcheck','--norestore',
        '-env:UserInstallation='.$userInstallation,
        '--convert-to','pdf:writer_pdf_Export',
        '--outdir',$work,
        $srcUri
    ];
    [$code1,$out1,$err1] = run_cmd($cmd1, $work, 300, $env);

    $pdfSafeBase = pathinfo($uploadedPath, PATHINFO_FILENAME);
    $pdfPath = $work . DIRECTORY_SEPARATOR . $pdfSafeBase . '.pdf';
    $exists1 = is_file($pdfPath) && filesize($pdfPath) > 0;

    // INTENTO 2: genérico
    if (!$exists1) {
        $cmd2 = [
            $soffice,'--headless','--nologo','--nodefault','--nolockcheck','--norestore',
            '-env:UserInstallation='.$userInstallation,
            '--convert-to','pdf',
            '--outdir',$work,
            $srcUri
        ];
        [$code2,$out2,$err2] = run_cmd($cmd2, $work, 300, $env);
    }

    $exists = is_file($pdfPath) && filesize($pdfPath) > 0;
    if (!$exists) {
        $dirList = [];
        foreach (scandir($work) ?: [] as $f) {
            if ($f==='.'||$f==='..') continue;
            $dirList[] = $f . '  (' . @filesize($work.DIRECTORY_SEPARATOR.$f) . ' bytes)';
        }
        $diag  = "No se generó {$pdfSafeBase}.pdf\n\n";
        $diag .= "INTENTO 1 (pdf:writer_pdf_Export) → code=$code1\nSTDERR:\n$err1\nSTDOUT:\n$out1\n\n";
        if (isset($code2)) {
            $diag .= "INTENTO 2 (pdf) → code=$code2\nSTDERR:\n".($err2??'')."\nSTDOUT:\n".($out2??'')."\n\n";
        }
        $diag .= "soffice: ".$soffice."\n";
        $diag .= "Contenido de la carpeta de trabajo:\n- " . implode("\n- ", $dirList);
        throw new RuntimeException($diag);
    }

    // Renombra a nombre base original para la descarga
    $finalBase = pathinfo($origName, PATHINFO_FILENAME);
    $finalPdf  = $work . DIRECTORY_SEPARATOR . $finalBase . '.pdf';
    if ($finalPdf !== $pdfPath) {
        @unlink($finalPdf);
        if (!@rename($pdfPath, $finalPdf)) {
            if (!@copy($pdfPath, $finalPdf)) {
                throw new RuntimeException('La conversión generó PDF pero no se pudo renombrar para la descarga.');
            }
            @unlink($pdfPath);
        }
    }

    // Entregar descarga
    $downloadName = $finalBase . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.rawurlencode($downloadName).'"');
    header('Content-Length: ' . filesize($finalPdf));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    readfile($finalPdf);

    // Limpieza best-effort
    @unlink($uploadedPath);
    @unlink($finalPdf);
    if (!PERSISTENT_PROFILE_PATH) {
        $it = new RecursiveDirectoryIterator($profile, FilesystemIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
        @rmdir($profile);
    }
    $it = new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS);
    $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($ri as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
    @rmdir($work);
    exit;

} catch (Throwable $e) {
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (isset($work) && is_dir($work)) {
        $it = new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
        @rmdir($work);
    }
    render_page("❌ $msg", 'error');
}
