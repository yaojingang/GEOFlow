document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-link-pool-import-form]');
    if (!form) return;

    const fileInput = form.querySelector('[name="file"]');
    const pasteInput = form.querySelector('[name="links"]');
    const dropzone = form.querySelector('[data-link-pool-upload-zone]');
    const selectedFile = form.querySelector('[data-link-pool-selected-file]');
    const fileName = form.querySelector('[data-link-pool-file-name]');
    const fileSize = form.querySelector('[data-link-pool-file-size]');
    const clearButton = form.querySelector('[data-link-pool-file-clear]');
    const error = form.querySelector('[data-link-pool-file-error]');
    if (!fileInput || !pasteInput || !dropzone || !selectedFile || !fileName || !fileSize || !clearButton || !error) return;

    form.classList.add('is-enhanced');

    const showError = (message) => {
        error.textContent = message;
        error.hidden = !message;
        if (message) fileInput.setAttribute('aria-invalid', 'true');
        else fileInput.removeAttribute('aria-invalid');
    };

    const clearFile = () => {
        fileInput.value = '';
        selectedFile.hidden = true;
        fileName.textContent = '';
        fileSize.textContent = '';
    };

    const renderFile = () => {
        const file = fileInput.files?.[0];
        if (!file) {
            clearFile();
            return;
        }
        if (!/\.(csv|txt)$/i.test(file.name)) {
            clearFile();
            showError('请选择 .csv 或 .txt 文件。');
            return;
        }
        if (file.size > 1024 * 1024) {
            clearFile();
            showError('文件不能超过 1 MB，请拆分后重试。');
            return;
        }
        showError('');
        pasteInput.value = '';
        fileName.textContent = file.name;
        fileSize.textContent = file.size < 1024 ? `${file.size} B` : `${(file.size / 1024).toFixed(1)} KB`;
        selectedFile.hidden = false;
    };

    fileInput.addEventListener('change', renderFile);
    clearButton.addEventListener('click', () => {
        clearFile();
        showError('');
        fileInput.focus();
    });
    pasteInput.addEventListener('input', () => {
        if (pasteInput.value.trim()) {
            clearFile();
            showError('');
        }
    });

    const containsFiles = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');
    ['dragenter', 'dragover'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => {
        if (!containsFiles(event)) return;
        event.preventDefault();
        dropzone.classList.add('is-dragging');
    }));
    dropzone.addEventListener('dragleave', (event) => {
        if (!dropzone.contains(event.relatedTarget)) dropzone.classList.remove('is-dragging');
    });
    dropzone.addEventListener('drop', (event) => {
        if (!containsFiles(event)) return;
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
        if (event.dataTransfer.files.length !== 1) {
            clearFile();
            showError('每次只能上传一个 CSV 或 TXT 文件。');
            return;
        }
        try {
            fileInput.files = event.dataTransfer.files;
            renderFile();
        } catch {
            clearFile();
            showError('请点击“选择 CSV 文件”打开文件选择器。');
        }
    });
    form.addEventListener('submit', (event) => {
        if (fileInput.files?.length || pasteInput.value.trim()) return;
        event.preventDefault();
        showError('请选择文件或粘贴 CSV 内容后再预检。');
        fileInput.focus();
    });
});
