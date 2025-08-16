# pdf
Conjunto de scripts que permiten diversas operaciones relacionadas con archivos PDF
# firma
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
