// Upload page behind an upload link: posts each chosen or dropped file to the
// page's own URL, which authenticates the upload. Texts come from the
// template's data-label-* attributes.
(() => {
    const drop = document.querySelector('[data-asset-upload]');
    const input = document.getElementById('asset-upload-files');
    const results = document.querySelector('[data-asset-upload-results]');
    if (!drop || !input || !results) {
        return;
    }

    for (const time of document.querySelectorAll('[data-local-time]')) {
        time.textContent = new Date(time.dateTime).toLocaleString([], {dateStyle: 'short', timeStyle: 'short'});
    }

    const upload = async (file) => {
        const row = document.createElement('li');
        const name = document.createElement('span');
        const state = document.createElement('span');
        name.textContent = file.name;
        state.textContent = drop.dataset.labelUploading;
        row.append(name, state);
        results.append(row);

        const body = new FormData();
        body.append('asset[resource]', file);
        try {
            const response = await fetch(window.location.href, {method: 'POST', body, headers: {Accept: 'application/json'}});
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error_description || `HTTP ${response.status}`);
            }
            row.dataset.state = 'done';
            state.textContent = drop.dataset.labelDone;
        } catch (error) {
            row.dataset.state = 'failed';
            state.textContent = `${drop.dataset.labelFailed}: ${error.message}`;
        }
    };

    const uploadAll = async (files) => {
        for (const file of files) {
            await upload(file);
        }
    };

    input.addEventListener('change', () => {
        uploadAll([...input.files]);
        input.value = '';
    });
    drop.addEventListener('dragover', (event) => {
        event.preventDefault();
        drop.classList.add('is-over');
    });
    drop.addEventListener('dragleave', () => drop.classList.remove('is-over'));
    drop.addEventListener('drop', (event) => {
        event.preventDefault();
        drop.classList.remove('is-over');
        uploadAll([...event.dataTransfer.files]);
    });
})();
