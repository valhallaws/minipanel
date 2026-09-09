"""Extract ZIP files as the isolated site user, without following links."""

import os
import secrets
import stat
import sys
import zipfile

MAX_BYTES = 10 * 1024 ** 3
MAX_ENTRIES = 100000
DIRECTORY_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW


def parts(path):
    if path == '.':
        return []
    if not path or path.startswith('/') or '\\' in path or '\x00' in path:
        raise ValueError('Ruta de archivo no permitida.')
    result = path.rstrip('/').split('/')
    if any(p in ('', '.', '..', '.git', '.ssh') or ':' in p or any(ord(c) < 32 for c in p) for p in result):
        raise ValueError('El ZIP contiene rutas protegidas o no permitidas.')
    return result


def directory(root_fd, segments, create=False):
    fd = os.dup(root_fd)
    try:
        for segment in segments:
            if create:
                try:
                    os.mkdir(segment, 0o755, dir_fd=fd)
                except FileExistsError:
                    pass
            next_fd = os.open(segment, DIRECTORY_FLAGS, dir_fd=fd)
            os.close(fd)
            fd = next_fd
        return fd
    except BaseException:
        os.close(fd)
        raise


def extract(root, source, destination, mode):
    if os.geteuid() == 0 or mode not in ('skip', 'replace'):
        raise ValueError('La extracción requiere el usuario aislado del sitio.')
    source_parts, destination_parts = parts(source), parts(destination)
    if not source_parts or not source.lower().endswith('.zip'):
        raise ValueError('Selecciona un archivo ZIP.')
    root_fd = os.open(root, DIRECTORY_FLAGS)
    try:
        parent_fd = directory(root_fd, source_parts[:-1])
        try:
            source_fd = os.open(source_parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent_fd)
        finally:
            os.close(parent_fd)
        with os.fdopen(source_fd, 'rb') as archive_file:
            source_stat = os.fstat(archive_file.fileno())
            if not stat.S_ISREG(source_stat.st_mode):
                raise ValueError('El origen no es un archivo regular.')
            with zipfile.ZipFile(archive_file) as archive:
                entries = archive.infolist()
                if len(entries) > MAX_ENTRIES or sum(e.file_size for e in entries) > MAX_BYTES:
                    raise ValueError('El ZIP supera 100000 entradas o 10 GB descomprimidos.')
                seen = set()
                for entry in entries:
                    path = parts(entry.filename)
                    kind = stat.S_IFMT(entry.external_attr >> 16)
                    if not path or kind not in (0, stat.S_IFREG, stat.S_IFDIR) or entry.flag_bits & 1:
                        raise ValueError('No se admiten enlaces, archivos especiales ni ZIP cifrados.')
                    key = '/'.join(path)
                    if key in seen or destination_parts + path == source_parts:
                        raise ValueError('El ZIP contiene duplicados o reemplazaría el archivo original.')
                    seen.add(key)
                destination_fd = directory(root_fd, destination_parts, create=True)
                written = skipped = total = 0
                try:
                    for entry in entries:
                        path = parts(entry.filename)
                        parent_fd = directory(destination_fd, path if entry.is_dir() else path[:-1], create=True)
                        try:
                            if entry.is_dir():
                                continue
                            name = path[-1]
                            try:
                                existing = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
                            except FileNotFoundError:
                                existing = None
                            if existing and (not stat.S_ISREG(existing.st_mode) or (existing.st_dev, existing.st_ino) == (source_stat.st_dev, source_stat.st_ino)):
                                raise ValueError('El destino contiene un enlace, un directorio o el ZIP original.')
                            if existing and mode == 'skip':
                                skipped += 1
                                continue
                            temporary = '.minipanel-extract-' + secrets.token_hex(12)
                            fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent_fd)
                            try:
                                with os.fdopen(fd, 'wb') as output, archive.open(entry) as content:
                                    while True:
                                        chunk = content.read(1024 * 1024)
                                        if not chunk:
                                            break
                                        total += len(chunk)
                                        if total > MAX_BYTES:
                                            raise ValueError('Límite de extracción excedido.')
                                        output.write(chunk)
                                    permissions = (stat.S_IMODE(existing.st_mode) & 0o777) if existing else 0o644
                                    os.fchmod(output.fileno(), 0o600 if name.startswith('.env') else permissions)
                                if mode == 'skip':
                                    try:
                                        os.link(temporary, name, src_dir_fd=parent_fd, dst_dir_fd=parent_fd, follow_symlinks=False)
                                    except FileExistsError:
                                        skipped += 1
                                        continue
                                else:
                                    os.replace(temporary, name, src_dir_fd=parent_fd, dst_dir_fd=parent_fd)
                                written += 1
                            finally:
                                try:
                                    os.unlink(temporary, dir_fd=parent_fd)
                                except FileNotFoundError:
                                    pass
                        finally:
                            os.close(parent_fd)
                finally:
                    os.close(destination_fd)
                print(f'Descompresión terminada: {written} archivos escritos, {skipped} ignorados. ZIP original conservado.')
    finally:
        os.close(root_fd)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 5:
            raise ValueError('Argumentos de extracción inválidos.')
        extract(*sys.argv[1:])
    except Exception as error:
        print(f'No se completó la extracción: {error}. Algunos archivos anteriores pueden haberse extraído.', file=sys.stderr)
        sys.exit(1)
