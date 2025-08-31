# pdf
Conjunto de scripts que permiten diversas operaciones relacionadas con archivos PDF
# firma
# firma.php
Este script permite firmar con .p12/.pfx un archivo pdf
Este script php permite subir un pdf y firmarlo con un certificado pfx/p12 protegido por contraseña . Una vez firmado el pdf se descarga con el sufijo "(firmado)" , sin que nada se guarde en el servidor (ni pdf ni por supuesto el certificado).  Es necesario:

- Establecer una conexión SIEMPRE protegida por SSL/TLS, aunque se emplee en un servidor local de la red.
- PHP ≥ 8.1 con proc_open/proc_close habilitados y subidas activas (no hay extensiones raras: vale el PHP base.)
- pyHanko (CLI) accesible en /usr/local/bin/pyhanko.Instalarlo en un venv de Python y crear un symlink.
- pdfinfo (paquete poppler-utils) para contar páginas cuando marcas en todas.
- Recomendado (solo si se emplea TSA/LTV online):
ca-certificates para validar TLS al contactar una TSA.

- Instalación mínima — Ubuntu/Debian:

sudo apt-get update
sudo apt-get install -y python3-venv python3-pip poppler-utils ca-certificates

# venv dedicado para pyHanko
sudo mkdir -p /opt/pyhanko
sudo python3 -m venv /opt/pyhanko
sudo /opt/pyhanko/bin/pip install --upgrade pip
sudo /opt/pyhanko/bin/pip install "pyHanko[pkcs11,image-support,opentype,qr]" pyhanko-cli

# symlink al binario 
sudo ln -sf /opt/pyhanko/bin/pyhanko /usr/local/bin/pyhanko

- Instalación mínima — RHEL/Alma/Rocky:

sudo dnf install -y python3 python3-virtualenv poppler-utils ca-certificates

sudo mkdir -p /opt/pyhanko
sudo python3 -m venv /opt/pyhanko
sudo /opt/pyhanko/bin/pip install --upgrade pip
sudo /opt/pyhanko/bin/pip install "pyHanko[pkcs11,image-support,opentype,qr]" pyhanko-cli

sudo ln -sf /opt/pyhanko/bin/pyhanko /usr/local/bin/pyhanko


(Si se emplea SELinux y habrá TSA: 
sudo setsebool -P httpd_can_network_connect 1.)

Comprobación rápida

pyhanko --version     # debe mostrar versión
pdfinfo -v            # debe responder

Recordatorio mínimo de PHP

file_uploads=On

upload_max_filesize y post_max_size ≥ 25M

proc_open/proc_close no en disable_functions

El usuario de PHP debe poder escribir en /tmp

# Conversor doc/docx/txt/rtf/odt a PDF

# Conversor de Documentos a PDF

Este proyecto permite convertir archivos **DOC, DOCX, ODT, RTF y TXT** en **PDF** directamente desde un navegador web.  
El sistema usa **LibreOffice en modo headless (sin interfaz gráfica)** y un script en **PHP** que ofrece una página web sencilla y elegante para subir los documentos.


## Requisitos

Antes de instalar, asegúrate de tener:

- Servidor **Linux** (Ubuntu/Debian recomendado).  
- **Apache** o **Nginx** con **PHP 8.x** funcionando.  
- Acceso de administrador (`sudo`).  
- **LibreOffice instalado desde APT** (no funciona con la versión *Snap*, debe instalarse al modo clásico).  

---

## Instalación

### 1. Instalar dependencias

Ejecuta en la terminal del servidor:

# Eliminar LibreOffice Snap si estuviera instalado
sudo snap remove libreoffice || true

# Actualizar paquetes
sudo apt update

# Instalar LibreOffice (versión APT)
sudo apt install -y libreoffice libreoffice-writer libreoffice-core ure uno-libs-private

# Instalar PHP y extensión fileinfo
sudo apt install -y php php-cli php-fpm php-common php-fileinfo

# Instalar fuentes para mejor compatibilidad
sudo apt install -y fonts-dejavu fonts-liberation fonts-noto ttf-mscorefonts-installer

# (Opcional) Instalar Java y diccionarios en español
sudo apt install -y default-jre hunspell-es hyphen-es

2. Copiar el script

Crea un directorio para el conversor en tu servidor web (por ejemplo, "conversor", puedes cambiarlo)

sudo mkdir -p /var/www/html/conversor

Copia el archivo convertir.php dentro:

sudo cp convertir.php /var/www/html/conversor/
sudo chown -R www-data:www-data /var/www/html/conversor

3. Ajustar PHP

Edita la configuración de PHP (php.ini):

sudo nano /etc/php/8.3/apache2/php.ini

(si dispones de otra versión, p.ej 8.2, cambia la url anterior)

Cambia o asegúrate de que estén así:

file_uploads = On
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 600
memory_limit = 256M


Además, en la sección disable_functions, asegúrate de que NO estén deshabilitadas estas funciones:

proc_open, proc_get_status, proc_terminate, exec

Reinicia el servicio web:

sudo systemctl restart apache2
o, si usas Nginx:
sudo systemctl restart php8.3-fpm
sudo systemctl restart nginx

4. (Opcional) Perfil persistente para LibreOffice

Si conviertes muchos documentos, puedes configurar un perfil persistente (más rápido y estable):

Edita convertir.php y cambia la línea:

const PERSISTENT_PROFILE_PATH = '/var/lib/lo_profile';

Crea la carpeta y dale permisos:

sudo mkdir -p /var/lib/lo_profile
sudo chown -R www-data:www-data /var/lib/lo_profile
sudo chmod 700 /var/lib/lo_profile

# Uso

Abre el navegador y entra en:

http://TU_SERVIDOR/conversor/convertir.php

Verás un formulario con un área para arrastrar o seleccionar un archivo.

Selecciona un documento en formato:

.doc (Word 97-2003)

.docx (Word moderno)

.odt (OpenDocument)

.rtf

.txt

Pulsa “Convertir a PDF”.

Se descargará un archivo PDF con el mismo nombre que tu documento original.

Los archivos temporales se borran automáticamente tras la conversión.

# Verificación

Puedes comprobar que LibreOffice funciona en modo headless:

sudo -u www-data /usr/bin/soffice --version


Y probar una conversión manual:

sudo -u www-data /usr/bin/soffice --headless --nologo --nodefault \
  --nolockcheck --norestore --convert-to pdf --outdir /tmp /ruta/a/ejemplo.docx

ls -lh /tmp/ejemplo.pdf

# Problemas comunes

“LibreOffice no encontrado” ->	Está instalado como Snap o no está en /usr/bin/soffice -> Solución:Instala con apt install libreoffice.

Error 77 (dconf/HOME) -> LibreOffice necesita un perfil propio ->Solución:Activa PERSISTENT_PROFILE_PATH como se explicó en el paso 4.

El PDF se genera con fuentes raras -> Faltan tipografías en el servidor -> Solución: Instala fonts-liberation, ttf-mscorefonts-installer u otras fuentes.

El archivo no sube (error tamaño) -> Límites de PHP o Nginx/Apache. Solución: Aumenta upload_max_filesize, post_max_size, client_max_body_size.

Error “proc_open disabled” -> PHP bloquea funciones de ejecución -> Solución: Edita php.ini y elimina proc_open, exec, etc. de disable_functions.

# Notas

El sistema es privado: los archivos subidos se eliminan al terminar.

No es necesario instalar base de datos.

El diseño del formulario usa solo HTML + CSS.

