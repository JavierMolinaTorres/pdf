<?php
declare(strict_types=1);
// FIRMAR PDF CON CERTIFICADOS .p12/.pfx
// -------- CONFIG --------
const PYHANKO_BIN   = '/usr/local/bin/pyhanko'; // ajusta si usaste otra ruta
const MAX_UPLOAD_MB = 25;                        // límite de tamaño por archivo
// ------------------------

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fail(string $msg, string $tech = ''): never {
    http_response_code(400);
    echo tpl($msg, $tech);
    exit;
}
function bytes_fmt(int $b): string {
    $u = ['B','KB','MB','GB']; $i=0;
    while ($b >= 1024 && $i < count($u)-1) { $b/=1024; $i++; }
    return sprintf('%.1f %s', $b, $u[$i]);
}
function run_cmd(array $argv, ?string &$stdout, ?string &$stderr, int &$code): void {
    $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $cmd = implode(' ', array_map('escapeshellarg', $argv));
    $proc = proc_open($cmd, $descriptors, $pipes, null, []);
    if (!\is_resource($proc)) { $stdout = $stderr = ''; $code = 127; return; }
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
}
function which(string $bin): ?string {
    $out=[]; $rc=0; @exec('command -v '.escapeshellarg($bin).' 2>/dev/null', $out, $rc);
    return $rc===0 && !empty($out) ? trim($out[0]) : null;
}
function mm_to_pt(float $mm): float { return $mm / 25.4 * 72.0; }

function tpl(string $msg = '', string $tech = ''): string {
    $err = $msg ? '<div class="err">'.$msg.'</div>' : '';
    $tech = $tech ? '<pre class="tech">'.h($tech).'</pre>' : '';
    $max = bytes_fmt((int) (MAX_UPLOAD_MB*1024*1024));
    $self = h($_SERVER['PHP_SELF'] ?? 'sign.php');
    return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Firmar PDF con certificado (.p12/.pfx)</title>
<style>
:root { color-scheme: light dark; --mut:#64748b }
*{box-sizing:border-box;font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Ubuntu,Arial,sans-serif}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:linear-gradient(135deg,#0f172a,#020617)}
.card{width:min(980px,94vw);background:#0b1220cc;border:1px solid #1f2937;border-radius:20px;padding:28px 28px 12px;color:#e5e7eb;box-shadow:0 10px 40px #00000066;backdrop-filter: blur(6px)}
h1{margin:0 0 8px;font-weight:700;font-size:24px}
p.sub{margin:0 0 18px;color:#94a3b8}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.full{grid-column:1/-1}
label{display:block;font-size:14px;color:#cbd5e1;margin:6px 0}
input[type="file"],input[type="text"],input[type="password"],input[type="number"],input[type="url"]{
 width:100%;padding:12px 14px;border:1px solid #334155;border-radius:12px;background:#0b1220;color:#e5e7eb;outline:none}
small{color:#94a3b8}
.row{display:flex;gap:12px;align-items:center}
.btn{appearance:none;border:0;background:linear-gradient(135deg,#22c55e,#16a34a);color:#03120a;
 padding:12px 18px;border-radius:12px;font-weight:700;cursor:pointer;box-shadow:0 6px 16px #16a34a55}
.btn:hover{filter:brightness(1.05)}
.err{background:#7f1d1d;color:#fee2e2;padding:12px;border-radius:12px;margin-bottom:10px;border:1px solid #fecaca}
.tech{max-height:220px;overflow:auto;background:#0a0f1a;color:#a7f3d0;padding:12px;border:1px dashed #334155;border-radius:10px}
hr{border:0;border-top:1px solid #1f2937;margin:16px 0}
.opt{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.toggle{display:flex;align-items:center; gap:8px; margin:6px 0 4px}
footer{color:var(--mut);font-size:12px;margin-top:8px}
</style>
<script>
function toggleVisible(e){
  const on = e.checked;
  for (const id of ['page','x','y','w','h','stamp_all']) document.getElementById(id).disabled = !on;
}
</script>
</head>
<body>
<div class="card">
  <h1>Firmar PDF (PKCS#12)</h1>
  <p class="sub">Sube tu PDF y un certificado .p12/.pfx. Tamaño máx: {$max}.</p>
  {$err}{$tech}
  <form method="post" enctype="multipart/form-data" action="{$self}">
    <div class="grid">
      <div class="full">
        <label>Documento PDF a firmar</label>
        <input type="file" name="pdf" accept="application/pdf,.pdf" required>
      </div>
      <div>
        <label>Certificado (.p12/.pfx)</label>
        <input type="file" name="p12" accept=".p12,.pfx,application/x-pkcs12" required>
      </div>
      <div>
        <label>Contraseña del .p12</label>
        <input type="password" name="p12pass" autocomplete="off" required>
      </div>

      <div class="full">
        <div class="toggle">
          <input type="checkbox" id="visible" name="visible" value="1" onchange="toggleVisible(this)">
          <label for="visible">Firma visible (si se desmarca, firma invisible)</label>
        </div>
        <div class="opt">
          <div><label>Página</label><input type="number" id="page" name="page" min="1" value="1" disabled></div>
          <div><label>X (mm)</label><input type="number" id="x" name="xmm" min="0" value="20" disabled></div>
          <div><label>Y (mm)</label><input type="number" id="y" name="ymm" min="0" value="10" disabled></div>
          <div><label>Ancho (mm)</label><input type="number" id="w" name="wmm" min="10" value="60" disabled></div>
          <div><label>Alto (mm)</label><input type="number" id="h" name="hmm" min="10" value="20" disabled></div>
        </div>
        <div class="row">
          <label class="row"><input type="checkbox" id="stamp_all" name="stamp_all" value="1" disabled> Repetir <em>marca</em> visible en todas las páginas (excepto la de firma)</label>
        </div>
        <small>La “marca” se aplica con <code>pyhanko stamp</code> y luego se firma. La firma criptográfica sigue siendo única.</small>
      </div>

      <div>
        <label>Motivo (opcional)</label>
        <input type="text" name="reason" placeholder="Aprobado, visto bueno, ...">
      </div>
      <div>
        <label>Localización (opcional)</label>
        <input type="text" name="location" placeholder="Madrid, ES">
      </div>
      <div class="full">
        <label>Timestamp TSA (URL RFC3161, opcional)</label>
        <input type="url" name="tsa" placeholder="https://tsa.ejemplo.com/tsa">
        <small>Si se informa y la TSA es pública, se añade sello de tiempo.</small>
      </div>
      <div class="full row">
        <label><input type="checkbox" name="ltv" value="1"> Añadir info de validación (LTV / PAdES B-LT)</label>
      </div>
      <div class="full row">
        <button class="btn" type="submit">Firmar y descargar</button>
      </div>
    </div>
  </form>
  <hr>
  <footer>Motor: pyHanko CLI. Stamping + firma (visible/invisible), TSA y LTV.</footer>
</div>
</body>
</html>
HTML;
}

function ensure_pyhanko(): void {
    $out=null;$err=null;$code=0;
    run_cmd([PYHANKO_BIN, '--help'], $out, $err, $code);
    if ($code !== 0) {
        $msg = "No encuentro pyHanko en '".PYHANKO_BIN."'.";
        $tech = "Salida:\n".$out."\n".$err."\nInstala con:\n".
                "  sudo /opt/pyhanko/bin/pip install 'pyHanko[pkcs11,image-support,opentype,qr]' pyhanko-cli\n".
                "y crea enlace: sudo ln -sf /opt/pyhanko/bin/pyhanko /usr/local/bin/pyhanko";
        fail($msg, $tech);
    }
}
function pdf_pages(string $pdf): int {
    $pdfinfo = which('pdfinfo') ?? '/usr/bin/pdfinfo';
    if (!is_file($pdfinfo) || !is_executable($pdfinfo)) {
        fail("Para repetir la marca en todas las páginas se requiere 'pdfinfo' (poppler-utils).",
             "Instala: sudo apt-get install -y poppler-utils");
    }
    $out=$err='';$code=0; run_cmd([$pdfinfo, $pdf], $out, $err, $code);
    if ($code!==0) fail("No puedo leer páginas con pdfinfo.", $out."\n".$err);
    if (preg_match('/^Pages:\s*(\d+)/mi', $out, $m)) return (int)$m[1];
    fail("No pude determinar el número de páginas.", $out);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo tpl(); exit; }
ensure_pyhanko();

// ---- Validaciones básicas
$maxBytes = (int)(MAX_UPLOAD_MB * 1024 * 1024);
if (empty($_FILES['pdf']['tmp_name']) || empty($_FILES['p12']['tmp_name'])) fail("Faltan archivos.");
if ($_FILES['pdf']['size'] > $maxBytes || $_FILES['p12']['size'] > $maxBytes) fail("Archivo demasiado grande.", "Límite: ".bytes_fmt($maxBytes));

$p12pass = $_POST['p12pass'] ?? '';
if ($p12pass === '') fail("La contraseña del .p12 es obligatoria.");

$origName = $_FILES['pdf']['name'] ?? 'documento.pdf';
$base = preg_replace('/\.pdf$/i', '', $origName);
$downloadName = $base . ' (firmado).pdf';

// ---- Carpeta temporal
$workdir = sys_get_temp_dir().'/sign_'.bin2hex(random_bytes(6));
if (!mkdir($workdir, 0700) && !is_dir($workdir)) fail("No pude crear carpeta temporal.");
$pdfPath = $workdir.'/input.pdf';
$p12Path = $workdir.'/cert.p12';
$passPath = $workdir.'/p.txt';
$outPath = $workdir.'/output.pdf';
$cfgPath = $workdir.'/pyhanko.yml';

if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfPath)) fail("No pude guardar el PDF.");
if (!move_uploaded_file($_FILES['p12']['tmp_name'], $p12Path)) fail("No pude guardar el .p12.");
file_put_contents($passPath, $p12pass); chmod($passPath, 0600);

// ---- Crear SIEMPRE un YAML básico con un estilo de stamp por defecto
$yaml = <<<YAML
stamp-styles:
  default:
    type: text
    border_width: 0.8         # más fina
    stamp-text: "Documento firmado digitalmente"
    # Puedes personalizar con fuentes, colores, logo, etc.
YAML;
file_put_contents($cfgPath, $yaml);
chmod($cfgPath, 0600);

// ---- Descubrir si la CLI soporta --passfile en pkcs12
$out=$err='';$code=0;
run_cmd([PYHANKO_BIN, 'sign', 'addsig', 'pkcs12', '--help'], $out, $err, $code);
$supportsPassfile = (strpos($out.$err, '--passfile') !== false);

// ---- Parámetros comunes (visible/invisible, coords)
$visible = !empty($_POST['visible']);
$fieldArg = 'Sig1';
$page = max(1, (int)($_POST['page'] ?? 1));
$xmm  = (float)($_POST['xmm'] ?? 20);
$ymm  = (float)($_POST['ymm'] ?? 20);
$wmm  = (float)($_POST['wmm'] ?? 60);
$hmm  = (float)($_POST['hmm'] ?? 20);

if ($visible) {
    // mm → pt y ENTEROS (pyHanko exige enteros)
    $x1 = max(0, (int) round(mm_to_pt($xmm)));
    $y1 = max(0, (int) round(mm_to_pt($ymm)));
    $x2 = max($x1 + 1, (int) round(mm_to_pt($xmm + $wmm)));
    $y2 = max($y1 + 1, (int) round(mm_to_pt($ymm + $hmm)));
    $fieldArg = "{$page}/{$x1},{$y1},{$x2},{$y2}/Firma1";

    // --- Estampar marca en todas las páginas antes de firmar (si lo piden)
    if (!empty($_POST['stamp_all'])) {
        $total = pdf_pages($pdfPath);
        $sx = $x1; $sy = $y1; // esquina inferior izq. del rectángulo de firma

        $cur = $pdfPath;
        for ($p = 1; $p <= $total; $p++) {
            if ($p === $page) continue; // evita duplicar en la página de la firma
            $next = $workdir.'/stamp_'.$p.'.pdf';
            // IMPORTANTE: --config debe ir ANTES del subcomando 'stamp'
            $cmd = [PYHANKO_BIN, '--config', $cfgPath, 'stamp',
                    '--style-name', 'default', '--page', (string)$p,
                    $cur, $next, (string)$sx, (string)$sy];
            $so=''; $se=''; $rc=0; run_cmd($cmd, $so, $se, $rc);
            if ($rc !== 0 || !is_file($next)) {
                fail("Fallo al estampar la marca en la página {$p}.",
                     "CMD:\n".implode(' ', array_map('escapeshellarg',$cmd))."\n\nSTDERR:\n".$se);
            }
            if ($cur !== $pdfPath && is_file($cur)) @unlink($cur);
            $cur = $next;
        }
        if ($cur !== $pdfPath) { @unlink($pdfPath); rename($cur, $pdfPath); }
    }
}

// ---- Construir comando addsig + flags comunes
$argv = [PYHANKO_BIN, 'sign', 'addsig'];
$argv[] = '--field'; $argv[] = $fieldArg;

if (($reason = trim((string)($_POST['reason'] ?? ''))) !== '') { $argv[]='--reason';   $argv[]=$reason; }
if (($loc    = trim((string)($_POST['location'] ?? ''))) !== ''){ $argv[]='--location'; $argv[]=$loc; }
if (($tsa    = trim((string)($_POST['tsa'] ?? ''))) !== '')      { $argv[]='--timestamp-url'; $argv[]=$tsa; }
if (!empty($_POST['ltv'])) { $argv[]='--with-validation-info'; $argv[]='--use-pades'; }

// --- Subcomando pkcs12 (dos caminos: con --passfile o config YAML)
if ($supportsPassfile) {
    $argv[] = 'pkcs12';
    $argv[] = '--passfile'; $argv[] = $passPath;
    $argv[] = $pdfPath; $argv[] = $outPath; $argv[] = $p12Path;
} else {
    // Ampliar el YAML existente con el setup de PKCS#12 (lo añadimos al final)
    $more = "\n".'pkcs12-setups:'."\n".'  websig:'."\n".
            '    pfx-file: '.str_replace('\\','/', $p12Path)."\n".
            '    pfx-passphrase: '.str_replace(["\r","\n"], '', $p12pass)."\n";
    file_put_contents($cfgPath, $more, FILE_APPEND);
    // Usar ese setup
    $argv[] = '--config'; $argv[] = $cfgPath;
    $argv[] = 'pkcs12';
    $argv[] = '--p12-setup'; $argv[] = 'websig';
    $argv[] = $pdfPath; $argv[] = $outPath;
}

// ---- Ejecutar firma
$stdout = $stderr = ''; $code = 0;
run_cmd($argv, $stdout, $stderr, $code);

// ---- Validar y responder
$tech = "CMD:\n".implode(' ', array_map(fn($a)=> (str_contains($a, $passPath) ? '***' : $a), $argv))
      ."\n\nSTDOUT:\n".$stdout."\n\nSTDERR:\n".$stderr."\n\nCODE: ".$code;

if ($code !== 0 || !is_file($outPath) || filesize($outPath) < 1000) {
    fail("No se pudo firmar el PDF.", $tech);
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.str_replace('"','',$downloadName).'"');
header('Content-Length: '.filesize($outPath));
readfile($outPath);

// Limpieza diferida
register_shutdown_function(function() use ($workdir) {
    foreach (['input.pdf','output.pdf','cert.p12','p.txt','pyhanko.yml'] as $f) @unlink($workdir.'/'.$f);
    @rmdir($workdir);
});
exit;
