window.databaseTransfer = (wire, startUrl) => ({
    uploading: false,
    ready: false,
    progress: 0,
    error: '',
    request: null,
    destroyed: false,
    destroy() { this.destroyed = true; this.request?.abort(); },
    async upload(file) {
        if (!file || this.uploading) return;
        this.error = '';
        this.ready = false;
        this.progress = 0;
        wire.set('uploadToken', '', false);
        if (!/\.sql$/i.test(file.name) || file.size < 1 || file.size > 1073741824) {
            this.error = 'Selecciona un archivo .sql de hasta 1 GB.';
            return;
        }
        this.uploading = true;
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        try {
            const response = await fetch(startUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({name: file.name, size: file.size}),
            });
            if (response.status === 429) throw new Error('Demasiados intentos de carga. Espera un minuto y vuelve a seleccionar el archivo.');
            if (!response.ok) throw new Error('No se pudo iniciar la carga. Revisa tu sesión y el espacio del servidor.');
            const upload = await response.json();
            for (let offset = 0; offset < file.size; offset += 1048576) {
                if (this.destroyed) return;
                const chunk = file.slice(offset, offset + 1048576);
                const form = new FormData();
                form.append('offset', offset);
                form.append('chunk', chunk, 'chunk.bin');
                await new Promise((resolve, reject) => {
                    const xhr = this.request = new XMLHttpRequest();
                    xhr.open('POST', upload.url);
                    xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.timeout = 120000;
                    xhr.upload.onprogress = event => {
                        if (event.lengthComputable) this.progress = Math.min(99, Math.floor((offset + chunk.size * event.loaded / event.total) * 100 / file.size));
                    };
                    xhr.onload = () => xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(new Error('La carga se interrumpió. Vuelve a seleccionar el archivo para intentarlo de nuevo.'));
                    xhr.onerror = xhr.ontimeout = xhr.onabort = () => reject(new Error('Conexión interrumpida durante la carga.'));
                    xhr.send(form);
                });
            }
            if (this.destroyed) return;
            wire.set('uploadToken', upload.token, false);
            this.progress = 100;
            this.ready = true;
        } catch (error) {
            this.error = error.message;
        } finally {
            this.uploading = false;
        }
    },
});

window.databaseDownloads = () => ({
    startDownload(id, url) {
        const key = 'minipanel.dump-download.' + id;
        if (sessionStorage.getItem(key)) return;
        sessionStorage.setItem(key, 'started');
        const frame = document.createElement('iframe');
        frame.hidden = true;
        frame.title = 'Descarga de dump';
        frame.src = url;
        document.body.appendChild(frame);
    },
});
