export function initializeFailClosedMaterialConfirmations(
    root,
    actionDialog = globalThis.window?.AdminActionDialog,
) {
    const controllerReady = typeof actionDialog === 'function' || typeof actionDialog?.confirm === 'function';
    if (!controllerReady) return;
    root.querySelectorAll('[data-material-delete-form]').forEach((form) => {
        const submitButton = form.querySelector('[data-material-delete-submit]');
        if (!submitButton || typeof submitButton.removeAttribute !== 'function') return;

        submitButton.disabled = false;
        submitButton.removeAttribute('aria-disabled');
    });
}

function formatFileSize(bytes) {
    if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(2)} MB`;
    if (bytes >= 1024) return `${(bytes / 1024).toFixed(2)} KB`;

    return `${bytes} B`;
}

export function initializeImageUploadForm(form) {
    const input = form.querySelector('input[type="file"][name="images[]"]');
    const dropzone = form.querySelector('[data-image-upload-dropzone]');
    const filesPanel = form.querySelector('[data-image-upload-files]');
    const fileList = form.querySelector('[data-image-upload-file-list]');
    const status = form.querySelector('[data-image-upload-status]');
    const submitButton = form.querySelector('[data-image-upload-submit]');
    const submitLabel = form.querySelector('[data-image-upload-submit-label]');
    const serverError = form.querySelector('[data-image-upload-server-error]');
    if (!input || !dropzone || !filesPanel || !fileList || !status || !submitButton || !submitLabel) return;

    const maxUploadBytes = Number(form.dataset.maxUploadBytes || 0);
    const allowedTypes = new Set((form.dataset.allowedTypes || '').split(',').filter(Boolean));
    const allowedExtensions = new Set((form.dataset.allowedExtensions || '').split(',').filter(Boolean));
    let hasInvalidFiles = false;

    const supportedImage = (file) => allowedTypes.has(file.type)
        || allowedExtensions.has((file.name || '').split('.').pop()?.toLowerCase());

    const setDropzoneError = (hasError) => {
        dropzone.classList.toggle('border-red-300', hasError);
        dropzone.classList.toggle('bg-red-50', hasError);
        dropzone.classList.toggle('border-gray-300', !hasError);
        dropzone.classList.toggle('bg-gray-50', !hasError);
    };

    const clearServerError = () => {
        if (!serverError) return;
        const describedBy = (input.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter((id) => id && id !== serverError.id);
        if (describedBy.length > 0) input.setAttribute('aria-describedby', describedBy.join(' '));
        else input.removeAttribute('aria-describedby');
        serverError.remove();
    };

    const renderFiles = () => {
        clearServerError();
        const files = Array.from(input.files || []);
        hasInvalidFiles = files.some((file) => !supportedImage(file)
            || (maxUploadBytes > 0 && file.size > maxUploadBytes));
        setDropzoneError(hasInvalidFiles);
        if (hasInvalidFiles) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
        fileList.replaceChildren();

        files.forEach((file) => {
            const item = form.ownerDocument.createElement('li');
            const name = form.ownerDocument.createElement('span');
            const size = form.ownerDocument.createElement('span');
            item.className = 'flex min-w-0 items-start justify-between gap-3 rounded-lg bg-white px-3 py-2 text-sm ring-1 ring-inset ring-gray-200';
            name.className = 'min-w-0 break-all text-gray-700';
            size.className = 'shrink-0 text-xs tabular-nums text-gray-500';
            name.textContent = file.name;
            size.textContent = formatFileSize(file.size);
            item.append(name, size);
            fileList.append(item);
        });

        filesPanel.classList.toggle('hidden', files.length === 0);
        if (files.length === 0) {
            status.textContent = '';
            return;
        }

        status.textContent = hasInvalidFiles
            ? form.dataset.invalidError || ''
            : (form.dataset.selectedLabel || '').replace('{count}', String(files.length));
        status.className = hasInvalidFiles ? 'min-h-5 text-sm text-red-600' : 'min-h-5 text-sm text-green-700';
    };

    input.addEventListener('change', renderFiles);
    form.addEventListener('submit', (event) => {
        const files = Array.from(input.files || []);
        if (files.length === 0 || hasInvalidFiles) {
            event.preventDefault();
            status.textContent = files.length === 0
                ? form.dataset.selectError || ''
                : form.dataset.invalidError || '';
            status.className = 'min-h-5 text-sm text-red-600';
            input.setAttribute('aria-invalid', 'true');
            setDropzoneError(true);
            input.focus();
            return;
        }

        form.setAttribute('aria-busy', 'true');
        submitButton.disabled = true;
        submitLabel.textContent = form.dataset.uploadingLabel || submitLabel.textContent;
        status.textContent = form.dataset.uploadingLabel || '';
        status.className = 'min-h-5 text-sm text-gray-600';
    });
}

export function initializeMaterialImagePreviews(surface) {
    const ownerDocument = surface.ownerDocument;
    const title = ownerDocument?.querySelector('[data-image-preview-title]');
    const preview = ownerDocument?.querySelector('[data-image-preview-image]');
    const info = ownerDocument?.querySelector('[data-image-preview-info]');
    const link = ownerDocument?.querySelector('[data-image-preview-url]');
    if (!title || !preview || !info || !link || !ownerDocument) return;

    surface.querySelectorAll('[data-image-preview-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const imageName = trigger.dataset.imageName || '';
            const imageUrl = trigger.dataset.imageUrl || '';
            title.textContent = imageName;
            preview.setAttribute('src', trigger.dataset.imageSrc || '');
            preview.setAttribute('alt', imageName);
            info.textContent = `${surface.dataset.imageDimensionsLabel || ''}: ${trigger.dataset.imageDimensions || ''} | ${surface.dataset.imageSizeLabel || ''}: ${trigger.dataset.imageSize || ''}`;
            link.setAttribute('href', imageUrl);
            link.textContent = imageUrl;

            const CustomEventConstructor = ownerDocument.defaultView?.CustomEvent;
            if (!CustomEventConstructor) return;
            ownerDocument.dispatchEvent(new CustomEventConstructor('geoflow:modal:open', {
                detail: { name: 'image-preview', opener: trigger },
            }));
        });
    });
}

export function initializeMaterialsStandalone(root = document) {
    initializeFailClosedMaterialConfirmations(root);
    root.querySelectorAll('[data-image-upload-form]').forEach(initializeImageUploadForm);
    root.querySelectorAll('[data-image-library-detail]').forEach(initializeMaterialImagePreviews);
}

if (typeof document !== 'undefined') initializeMaterialsStandalone(document);
