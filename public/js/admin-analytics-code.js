(function () {
    const form = document.querySelector('[data-analytics-settings-form]');
    if (!form) return;

    form.addEventListener('formdata', function (event) {
        if (!event.formData.has('analytics_code')) return;

        const bytes = new TextEncoder().encode(event.formData.get('analytics_code'));
        const binary = Array.from(bytes, byte => String.fromCharCode(byte)).join('');
        event.formData.set('analytics_code_base64', btoa(binary));
        event.formData.delete('analytics_code');
    });
})();
