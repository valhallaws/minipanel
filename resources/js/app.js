import './dns-map';
import { Passkeys } from '@laravel/passkeys';

const passkeyError = (error) => error?.message || 'No se pudo completar la operación con passkey.';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-passkey-login]').forEach((button) => {
        button.addEventListener('click', async () => {
            const status = document.querySelector('[data-passkey-status]');
            button.disabled = true;
            if (status) status.textContent = 'Esperando confirmación del dispositivo…';

            try {
                const response = await Passkeys.verify({ remember: document.querySelector('[name="remember"]')?.checked });
                window.location.assign(response.redirect || '/');
            } catch (error) {
                if (status) status.textContent = passkeyError(error);
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-passkey-register]').forEach((button) => {
        button.addEventListener('click', async () => {
            const nameInput = document.querySelector('[data-passkey-name]');
            const status = document.querySelector('[data-passkey-register-status]');
            const name = nameInput?.value?.trim();

            if (!name) {
                if (status) status.textContent = 'Ponle un nombre a este dispositivo.';
                nameInput?.focus();
                return;
            }

            button.disabled = true;
            if (status) status.textContent = 'Confirma con la biometría o PIN de tu dispositivo…';

            try {
                await Passkeys.register({ name });
                window.location.reload();
            } catch (error) {
                if (status) status.textContent = passkeyError(error);
                button.disabled = false;
            }
        });
    });
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
}
